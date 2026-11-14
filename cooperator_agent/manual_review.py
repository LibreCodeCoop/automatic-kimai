"""Explicit worker-confirmed internal preview, never an inferred timesheet.

A user supplies start, end, task category and confirms that this was human
work excluding breaks. GET existing Kimai timesheets before considering POST.
Shares the daily SQLite ledger and lock so unattended sync cannot race or
duplicate the self-reported interval. An uncertain POST is NEVER retried blindly.

Self-report is not payroll approval, a verified elapsed duration, or a claim
that ChatGPT autonomously processing a task was a human working.
"""
from __future__ import annotations
import datetime as dt
import hashlib
import json
import re
from dataclasses import replace
from pathlib import Path

from kimai_client import KimaiClient,load_dotenv
from .profile import Profile
from .reconcile import (Ledger,Decision,execute,plan_one,remote_day_list,
                        reconcile_pending,validate_catalog)
from .segment import Window
from .lock import acquire,release
from .secrets import read_credentials


def bounds(profile:Profile,day:str,start_hm:str,end_hm:str,now:dt.datetime):
    if now.tzinfo is None:
        raise ValueError("Current time must have a timezone")
    try:
        date=dt.date.fromisoformat(day)
        start=dt.datetime.fromisoformat(day+"T"+start_hm).replace(tzinfo=profile.zone)
        end=dt.datetime.fromisoformat(day+"T"+end_hm).replace(tzinfo=profile.zone)
    except (TypeError,ValueError,OverflowError):
        raise ValueError("Use YYYY-MM-DD and HH:MM on the same day") from None
    if (not re.fullmatch(r"\d{2}:\d{2}",start_hm) or
        not re.fullmatch(r"\d{2}:\d{2}",end_hm) or
        start.date()!=date or end.date()!=date):
        raise ValueError("Use HH:MM in the selected local calendar day")
    # Round-trip timezone conversion catches non-existent wall-clock times.
    for item in (start,end):
        if item.astimezone(dt.timezone.utc).astimezone(profile.zone)!=item:
            raise ValueError("Invalid local timezone transition")
    if not (dt.timedelta(minutes=5)<=end-start<=dt.timedelta(hours=profile.max_session_hours)):
        raise ValueError("Confirm at least 5 min and at most the per-session hour limit")
    local_now=now.astimezone(profile.zone)
    if end>local_now-dt.timedelta(minutes=1):
        raise ValueError("Past work only; do not record future time")
    if start<local_now-dt.timedelta(days=7):
        raise ValueError("Older than seven days needs separate historic review")
    return start,end


def self_report_window(profile:Profile,day:str,start_hm:str,end_hm:str,
                       task:str,now:dt.datetime):
    start,end=bounds(profile,day,start_hm,end_hm,now)
    task=" ".join(task.strip().split())
    if not 3<=len(task)<=64 or any(c in task for c in "<>\r\n"):
        raise ValueError("Provide a short work category, 3-64 characters")
    if any(c in task for c in ("\u2028","\u2029")):
        raise ValueError("Invalid task category")
    material=json.dumps({
        "profile":profile.profile_id,"method":"worker_explicit_confirmation",
        "start":start.isoformat(),"end":end.isoformat(),
        "task":task,
    },ensure_ascii=False,sort_keys=True)
    digest=hashlib.sha256(material.encode()).hexdigest()
    return Window(
        id="self"+digest[:21],date=day,start=start,end=end,
        # Zero direct sensor-proof minutes; the human self-reported the time.
        work_seconds=0,chat_unknown_seconds=0,
        uncovered_seconds=int((end-start).total_seconds()),
        ongoing=False,topics=(task,),evidence_digest=digest)


BASIS_VALUES=("worker_explicit_confirmation",
              "reported_attendance_scheduled_bounds",
              "reported_workday_observed_desktop_bounds")

def _declaration(ledger:Ledger,window:Window,user_id:int,basis:str):
    if basis not in BASIS_VALUES:
        raise ValueError("Unrecognized human work provenance")
    ledger.db.execute("""CREATE TABLE IF NOT EXISTS human_declarations (
        session_id TEXT PRIMARY KEY, declared_utc TEXT NOT NULL,
        local_start TEXT NOT NULL, local_end TEXT NOT NULL,
        category TEXT NOT NULL, user_id INTEGER NOT NULL,
        basis TEXT NOT NULL, payroll_approved INTEGER NOT NULL DEFAULT 0)""")
    previous=ledger.db.execute(
        "SELECT local_start,local_end,category,user_id,basis FROM human_declarations "
        "WHERE session_id=?",(window.id,)).fetchone()
    expected=(window.start.isoformat(),window.end.isoformat(),
              window.topics[0],user_id,basis)
    if previous is not None and previous!=expected:
        raise RuntimeError("Conflicting prior human declaration")
    if previous is None:
        ledger.db.execute("INSERT INTO human_declarations VALUES (?,?,?,?,?,?,?,0)",
           (window.id,dt.datetime.now(dt.timezone.utc).isoformat(),
            window.start.isoformat(),window.end.isoformat(),window.topics[0],
            user_id,basis))


def submit(*,profile_path:Path,env_path:Path,day:str,start_hm:str,
           end_hm:str,task:str,confirmed:bool=False,now=None,
           client=None,basis="worker_explicit_confirmation"):
    if basis not in BASIS_VALUES:
        raise ValueError("Unrecognized human work provenance")
    if confirmed is not True:
        raise ValueError("The worker must explicitly confirm the actual work period")
    profile=Profile.read(profile_path)
    instant=(now or dt.datetime.now(profile.zone)).astimezone(profile.zone)
    window=self_report_window(profile,day,start_hm,end_hm,task,instant)
    env=load_dotenv(Path(env_path))
    env.update(read_credentials(Path(env_path),profile_id=profile.profile_id))
    if (env.get("KIMAI_WRITE_ENABLED")!="true" and
        env.get("KIMAI_MANUAL_PREVIEW_ENABLED")!="true"):
        raise ValueError("Kimai manual preview writes have not been enabled for this profile")
    if not env.get("KIMAI_API_TOKEN"):
        raise ValueError("Kimai user token unavailable")
    api=client or KimaiClient(env["KIMAI_BASE_URL"],env["KIMAI_API_TOKEN"])
    user,_=api.get("/users/me")
    uid=user.get("id")
    if type(uid) is not int or uid<1 or user.get("timezone")!=profile.timezone:
        raise ValueError("Kimai user/timezone does not match this worker")
    validate_catalog(api,profile)
    lock=acquire(profile.state_dir/"daily.lock")
    try:
        ledger=Ledger(profile.state_dir/"ledger.sqlite3",
                      user_id=uid,profile_id=profile.profile_id)
        try:
            pending=ledger.pending()
            query_days={window.start.date()}
            for item in pending:
                try:
                    query_days.add(dt.date.fromisoformat(
                        item["payload"]["kimai"]["begin"][:10]))
                except (KeyError,ValueError,TypeError):
                    raise RuntimeError("Outbox contains malformed pending operation") from None
            recovery=reconcile_pending(api,profile,uid,ledger,sorted(query_days))
            if recovery["unresolved"]:
                raise RuntimeError("An uncertain Kimai operation must be reconciled first")
            remote=remote_day_list(api,uid,[window.start.date()],profile.zone)
            known=ledger.get(window.id)
            decision=plan_one(window,profile,remote,known,user_id=uid)
            if decision.action=="unchanged":
                _declaration(ledger,window,uid,basis)
                return {"status":"already_recorded","remote_id":decision.remote_id,
                        "day":day,"minutes":(window.end-window.start).total_seconds()/60}
            if decision.action!="post":
                raise ValueError("Cannot self-report over an existing timesheet: "+decision.reason)
            _declaration(ledger,window,uid,basis)
            # This is a single explicitly confirmed manual operation. The
            # automatic sync's opt-in remains off if the worker chose review_only.
            manual_profile=replace(profile,sync_mode="preview_nonbillable",
                                   allow_auto_nonbillable_preview=True)
            result=execute(decision,client=api,profile=manual_profile,
                           user_id=uid,ledger=ledger)
            return {**result,"day":day,
                    "minutes":int((window.end-window.start).total_seconds()/60),
                    "basis":basis,"bounds_estimated":basis!="worker_explicit_confirmation",
                    "billable":False,"payroll_approved":False}
        finally:
            ledger.close()
    finally:
        release(lock)
