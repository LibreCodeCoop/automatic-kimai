"""One-shot cross-platform 30-minute cycle: collect -> reconcile -> publish.

The OS scheduler runs this process while the user's machine/session is active.
A checkpoint allows catch-up after poweroff/restart; no artificial work is
generated while switched off. A shutdown capture is optional/best effort, not
a guarantee (network and Python may already be stopped).
"""
from __future__ import annotations
import argparse
import datetime as dt
import json
import os
import sys
from dataclasses import replace
from pathlib import Path

from kimai_client import save_private_json
from .collector import collect_days,CollectionUnavailable
from .daily import run as reconcile
from .paths import locations,private_dir
from .profile import Profile
from .providers import read_sources
from .secrets import read_credentials

MAX_CATCHUP_DAYS=7

def checkpoint_days(profile:Profile, now:dt.datetime, state:dict) -> tuple[int,bool]:
    previous=state.get("last_successful_utc")
    if not previous:return profile.lookback_days,False
    try:
        when=dt.datetime.fromisoformat(previous.replace("Z","+00:00")).astimezone(profile.zone)
    except (ValueError,TypeError):
        return profile.lookback_days,False
    gap=max(0,(now.date()-when.date()).days)
    days=max(profile.lookback_days,gap+1)
    return min(days,MAX_CATCHUP_DAYS),days>MAX_CATCHUP_DAYS

def run_once(*,profile_path:Path,env_path:Path,apply:bool=False,
             capture_only:bool=False,now:dt.datetime|None=None,
             collector=collect_days,reconciler=reconcile):
    profile=Profile.read(profile_path)
    instant=(now or dt.datetime.now(profile.zone)).astimezone(profile.zone)
    private_dir(profile.state_dir)
    status_path=profile.state_dir/"last_cycle_status.json"
    try:
        previous=json.loads(status_path.read_text(encoding="utf-8")) if status_path.is_file() else {}
        if not isinstance(previous,dict):previous={}
    except (ValueError,OSError):
        previous={}
    lookback,truncated=checkpoint_days(profile,instant,previous)
    status={
        "profile_id":profile.profile_id,
        "at_local":instant.isoformat(),
        "sync_interval_minutes":30,
        "lookback_days":lookback,
        "older_backlog_needs_manual_review":truncated,
        "last_successful_utc":previous.get("last_successful_utc"),
        "attempted_publication":False,
        "result":"started",
        "notes":"Only sanitized local evidence; never approval of human/payroll hours"
    }
    try:
        # Re-read ActivityWatch's persisted history on each run, including
        # the previous calendar day when recovering after a shutdown.
        capture=collector(profile,now=instant,days=lookback)
        status["collected"]=capture
        if capture_only:
            status["result"]="capture_only"
            return status
        enabled=read_sources(profile_path)
        creds=read_credentials(env_path,profile_id=profile.profile_id)
        source_results={"activitywatch":"collected"}
        if enabled.get("nextcloud_calendar"):
            # Without the personal calendar, automatic publication could
            # silently include a private appointment. Fail CLOSED.
            from .nextcloud_sources import collect_calendar
            required=("NEXTCLOUD_URL","NEXTCLOUD_USERNAME","NEXTCLOUD_APP_PASSWORD")
            if not all(creds.get(k) for k in required):
                raise CollectionUnavailable("Nextcloud Calendar is enabled but credentials are missing")
            source_results["nextcloud_calendar"]=collect_calendar(profile,creds,now=instant)
        if enabled.get("nextcloud_talk"):
            from .nextcloud_sources import collect_talk
            required=("NEXTCLOUD_URL","NEXTCLOUD_USERNAME","NEXTCLOUD_APP_PASSWORD")
            if all(creds.get(k) for k in required):
                try:source_results["nextcloud_talk"]=collect_talk(profile,creds)
                except Exception:source_results["nextcloud_talk"]="unavailable_context_only"
            else:
                source_results["nextcloud_talk"]="not_configured"
        if enabled.get("github"):
            from .github_source import collect_github
            try:source_results["github"]=collect_github(profile,creds)
            except Exception:source_results["github"]="unavailable_context_only"
        if enabled.get("telegram"):
            from .telegram_source import collect_telegram
            import asyncio
            try:source_results["telegram"]=asyncio.run(collect_telegram(profile,creds))
            except Exception:source_results["telegram"]="unavailable_or_authentication_required"
        if enabled.get("chatgpt_browser"):
            source_results["chatgpt_browser"]="ActivityWatch tab context only"
        if enabled.get("claude_browser"):
            source_results["claude_browser"]="ActivityWatch tab context only"
        status["sources"]=source_results
        # If any recent day is unavailable, the daily agent still fails
        # closed on that date instead of inventing activity.
        status["attempted_publication"]=bool(apply)
        report=reconciler(profile_path=profile_path,env_path=env_path,
                          apply=apply,now=instant,lookback_days_override=lookback)
        status["result"]="success"
        status["remote_writes"]=sum(s.get("result",{}).get("status")=="reconciled"
            for day in report["days"] for s in day.get("sessions",[]))
        status["review_count"]=sum(s.get("action")=="review"
            for day in report["days"] for s in day.get("sessions",[]))
        status["coverage_gap_count"]=report.get("coverage_gap_count",0)
        status["manual_review_interval_count"]=report.get("manual_review_interval_count",0)
        status["missing_workday_count"]=report.get("missing_workday_count",0)
        status["calendar_gap_count"]=report.get("calendar_gap_count",0)
        status["overnight_review_count"]=report.get("overnight_review_count",0)
        status["last_successful_utc"]=instant.astimezone(dt.timezone.utc).isoformat()
        return status
    except (CollectionUnavailable,ConnectionError,TimeoutError) as exc:
        status["result"]="offline_or_collection_unavailable"
        status["error_type"]=type(exc).__name__
        raise
    except Exception as exc:
        status["result"]="failed_retry_next_cycle"
        status["error_type"]=type(exc).__name__
        raise
    finally:
        # Durable checkpoint/status even after offline failure; collector's
        # data and the Kimai outbox are NEVER cleared on failed upload.
        save_private_json(status_path,status)
        from .trial import record_cycle
        record_cycle(profile.state_dir/"trial_monitor.sqlite3",status)

def main():
    p=argparse.ArgumentParser(description=__doc__)
    defaults=locations()
    p.add_argument("--profile",type=Path,default=defaults.profile)
    p.add_argument("--env-file",type=Path,default=defaults.credentials)
    p.add_argument("--apply",action="store_true",
                   help="Allow only explicitly opted-in, nonbillable internal previews")
    p.add_argument("--capture-only",action="store_true",
                   help="Best-effort final local snapshot, no Kimai API mutation")
    p.add_argument("--status",action="store_true")
    args=p.parse_args()
    if args.status:
        profile=Profile.read(args.profile)
        path=profile.state_dir/"last_cycle_status.json"
        if not path.is_file():
            print("STATUS",json.dumps({"result":"never_ran"}))
        else:
            safe=json.loads(path.read_text(encoding="utf-8"))
            safe.pop("collected",None)
            print("STATUS",json.dumps(safe,sort_keys=True))
        return 0
    try:
        status=run_once(profile_path=args.profile,env_path=args.env_file,
                        apply=args.apply,capture_only=args.capture_only)
    except (Exception,) as exc:
        print("AUTOMATIC_KIMAI_CYCLE_FAILED",type(exc).__name__,
              "local evidence remains for next run",file=sys.stderr)
        return 2
    print("AUTOMATIC_KIMAI_CYCLE",status["result"],
          "APPLY",args.apply,"REMOTE_WRITES",status.get("remote_writes",0),
          "REVIEW",status.get("review_count",0))
    return 0

if __name__=="__main__":
    raise SystemExit(main())
