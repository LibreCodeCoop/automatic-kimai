"""Work performed away from the computer, with explicit attendance evidence.

A calendar entry alone is NEVER proof of attendance. A user report confirms
presence, but planned start/end and planned breaks remain estimates. Keep
that distinction in a private manifest and in the manual-preview ledger.

This module does not read or modify private messages. It reuses the guarded
manual Kimai publication workflow, which never approves billing or payroll.
"""
from __future__ import annotations
from dataclasses import dataclass
import datetime as dt
import hashlib
import json
from pathlib import Path

from kimai_client import save_private_json
from .profile import Profile
from .manual_review import submit,bounds


@dataclass(frozen=True)
class AttendancePlan:
    day: str
    label: str
    start: str
    end: str
    breaks: tuple[tuple[str,str],...]
    schedule_source: str
    attendance_attested: bool
    exact_times_confirmed: bool=False


def planned_segments(plan:AttendancePlan,profile:Profile,now=None):
    if plan.attendance_attested is not True:
        raise ValueError("Calendar schedule alone is not attendance")
    if not plan.schedule_source.strip():
        raise ValueError("Source of prior schedule required")
    clock=now or dt.datetime.now(profile.zone)
    start,end=_parse(plan.day,plan.start,profile),_parse(plan.day,plan.end,profile)
    if (start.date().isoformat()!=plan.day or end.date().isoformat()!=plan.day
        or end<=start or end-start>dt.timedelta(hours=12)
        or end>clock.astimezone(profile.zone)-dt.timedelta(minutes=1)
        or start<clock.astimezone(profile.zone)-dt.timedelta(days=7)):
        raise ValueError("Invalid or outdated scheduled attendance day")
    if not 3<=len(plan.label.strip())<=64:
        raise ValueError("Attendance task label must be 3 to 64 characters")
    spans=[(start,end)]
    for left,right in plan.breaks:
        x,y=_parse(plan.day,left,profile),_parse(plan.day,right,profile)
        if not start<=x<y<=end:
            raise ValueError("Break must be fully inside the scheduled interval")
        next_spans=[]
        for a,b in spans:
            if y<=a or x>=b:next_spans.append((a,b))
            else:
                if a<x:next_spans.append((a,x))
                if y<b:next_spans.append((y,b))
        spans=next_spans
    if not spans:
        raise ValueError("No work duration remains after breaks")
    for a,b in spans:
        if b-a>dt.timedelta(hours=profile.max_session_hours):
            raise ValueError("Split the schedule into smaller attended intervals")
        if b-a<dt.timedelta(minutes=5):
            raise ValueError("Scheduled fragments shorter than five minutes")
        bounds(profile,plan.day,a.strftime("%H:%M"),b.strftime("%H:%M"),clock)
    return [(a.strftime("%H:%M"),b.strftime("%H:%M")) for a,b in spans]


def _parse(day,clock,profile):
    if len(clock)!=5 or clock[2]!=":":
        raise ValueError("Expected HH:MM")
    return dt.datetime.fromisoformat(day+"T"+clock).replace(tzinfo=profile.zone)


def record_plan(profile:Profile,plan:AttendancePlan,*,now=None):
    segments=planned_segments(plan,profile,now)
    path=profile.state_dir/"offscreen_attendance.json"
    if path.is_symlink():
        raise ValueError("Refusing symlinked attendance record")
    previous=json.loads(path.read_text(encoding="utf-8")) if path.exists() else {
        "schema_version":1,"events":{}}
    if previous.get("schema_version")!=1 or not isinstance(previous.get("events"),dict):
        raise ValueError("Unknown attendance ledger format")
    key=hashlib.sha256(
        (profile.profile_id+"|"+plan.day+"|"+plan.label+"|"+plan.schedule_source).encode()
    ).hexdigest()[:24]
    entry={
        "date":plan.day,"label":plan.label,"scheduled_start":plan.start,
        "scheduled_end":plan.end,"scheduled_breaks":[list(b) for b in plan.breaks],
        "scheduled_segments":[list(b) for b in segments],
        "source":plan.schedule_source,
        "attendance_attested":True,
        "exact_times_confirmed":plan.exact_times_confirmed,
        "bounds_estimated":not plan.exact_times_confirmed,
        "payroll_approved":False,"billable":False,
        "recorded_utc":dt.datetime.now(dt.timezone.utc).isoformat(),
    }
    if key in previous["events"]:
        historic=dict(previous["events"][key])
        historic.pop("recorded_utc",None)
        if historic!={k:v for k,v in entry.items() if k!="recorded_utc"}:
            raise ValueError("An attendance plan changed; review the existing records")
        return key,segments
    previous["events"][key]=entry
    save_private_json(path,previous)
    return key,segments


def apply_plan(*,profile_path:Path,env_path:Path,plan:AttendancePlan,
               attendance_confirmed:bool,now=None):
    if attendance_confirmed is not True or plan.attendance_attested is not True:
        raise ValueError("Worker attendance must be explicitly confirmed")
    profile=Profile.read(profile_path)
    now=now or dt.datetime.now(profile.zone)
    key,segments=record_plan(profile,plan,now=now)
    basis=("worker_explicit_confirmation" if plan.exact_times_confirmed
           else "reported_attendance_scheduled_bounds")
    results=[]
    for start,end in segments:
        result=submit(profile_path=profile_path,env_path=env_path,
                      day=plan.day,start_hm=start,end_hm=end,task=plan.label,
                      confirmed=True,basis=basis,now=now)
        results.append({"start":start,"end":end,**result})
    return {"plan_key":key,"day":plan.day,"bounds_estimated":not plan.exact_times_confirmed,
            "attendance_attested":True,"results":results,
            "payroll_approved":False,"billable":False}


def read_plan_records(profile:Profile,days:tuple[str,...]|list[str]):
    path=profile.state_dir/"offscreen_attendance.json"
    if not path.is_file():return []
    if path.is_symlink():raise ValueError("Symlinked attendance record")
    obj=json.loads(path.read_text(encoding="utf-8"))
    if obj.get("schema_version")!=1 or not isinstance(obj.get("events"),dict):
        raise ValueError("Unsupported attendance record")
    return [dict(item) for item in obj["events"].values()
            if item.get("date") in days and item.get("attendance_attested") is True]
