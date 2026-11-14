"""Show unrecorded *observed activity*, never guess payable time.

A daily review used to hide every candidate discarded before reaching Kimai
(short sessions, unsupported work apps and uncertain ChatGPT/Telegram context).
This module accounts for all non-AFK source intervals against live timesheets
and emits explicit gaps for human review. Gaps are NOT automatically billed.
"""
from __future__ import annotations

import datetime as dt
import json,os
from pathlib import Path
from .segment import classify,seconds,subtract,union

def local_action_anchors(profile):
    """Read only pre-sanitized own-action timestamps, never message content."""
    timestamps=[]
    for name in ("nextcloud_talk_context.json","telegram_context.json"):
        path=profile.cache_dir/name
        if not path.is_file():continue
        if path.is_symlink() or (os.name!="nt" and path.stat().st_mode&0o077):
            raise ValueError("Unsafe local action-evidence file")
        obj=json.loads(path.read_text(encoding="utf-8"))
        if name.startswith("nextcloud"):
            samples=[
                m for room in obj.get("observations",[])
                for m in room.get("message_events",[])
                if m.get("outgoing") is True
            ]+obj.get("own_actions",[])
        else:
            samples=[m for m in obj.get("observations",[])
                     if m.get("outgoing") is True]
        for item in samples[:20000]:
            value=item.get("utc") or item.get("timestamp_utc")
            if not value:continue
            try:
                instant=_aware(value).astimezone(profile.zone)
                if instant.tzinfo is not None:timestamps.append(instant)
            except (ValueError,TypeError,AttributeError,OverflowError):
                continue
    return sorted(set(timestamps))



def _aware(value):
    if isinstance(value,dt.datetime):
        return value
    return dt.datetime.fromisoformat(str(value).replace("Z","+00:00"))


def uncovered_activity(day:str,events:list[dict],remote:list[dict],profile,
                       now:dt.datetime,calendar=(),action_anchors=(),
                       *,min_gap_minutes:int=30,min_active_minutes:int=10):
    date=dt.date.fromisoformat(day)
    floor=dt.datetime.combine(date,dt.time.min,tzinfo=profile.zone)
    ceiling=min(floor+dt.timedelta(days=1),now.astimezone(profile.zone))
    if ceiling<=floor:return []
    visible=[];work=[];chat=[];unknown_idle=[]
    for event in events:
        try:
            a=max(floor,_aware(event["start"]).astimezone(profile.zone))
            b=min(ceiling,_aware(event["end"]).astimezone(profile.zone))
            kind=classify(event,profile)
        except (KeyError,ValueError,AttributeError,TypeError,OverflowError):
            continue
        if a>=b or event.get("private") or event.get("afk"):
            continue
        if event.get("idle_status_known") is not True:
            unknown_idle.append((a,b))
            continue
        visible.append((a,b))
        if kind=="work":work.append((a,b))
        if kind=="chat":chat.append((a,b))
    visible=union(visible)
    actions=sorted(t.astimezone(profile.zone) for t in action_anchors
                   if floor<=t.astimezone(profile.zone)<ceiling)
    if not visible and len(actions)<2:return []
    starts=[visible[0][0]] if visible else []
    ends=[visible[-1][1]] if visible else []
    if actions:
        starts.append(actions[0])
        # Include the final discrete action in the half-open warning range.
        ends.append(min(ceiling,actions[-1]+dt.timedelta(seconds=1)))
    lower=min(starts)
    upper=max(ends)
    if upper<=lower:return []
    recorded=[]
    for row in remote:
        try:
            if not row.get("begin") or not row.get("end"):continue
            a=max(lower,_aware(row["begin"]).astimezone(profile.zone))
            b=min(upper,_aware(row["end"]).astimezone(profile.zone))
            if a<b:recorded.append((a,b))
        except (KeyError,ValueError,AttributeError,TypeError,OverflowError):
            continue
    # Scheduled private appointments are not silently treated as omissions.
    excluded=[]
    for item in calendar:
        if item.get("date")!=day or item.get("all_day"):continue
        if item.get("category") not in ("personal_excluded","calendar_declined_excluded"):
            continue
        try:
            a=max(lower,_aware(item["start_local"]).astimezone(profile.zone))
            b=min(upper,_aware(item["end_local"]).astimezone(profile.zone))
            if a<b:excluded.append((a,b))
        except (KeyError,ValueError,AttributeError,TypeError,OverflowError):
            continue
    holes=subtract([(lower,upper)],union(recorded+excluded))
    warnings=[]
    for a,b in holes:
        elapsed=(b-a).total_seconds()
        if elapsed<min_gap_minutes*60:continue
        observed=seconds(visible,a,b)
        own_actions=sum(a<=t<b for t in actions)
        if observed<min_active_minutes*60 and own_actions<2:continue
        direct=seconds(work,a,b)
        contextual=seconds(chat,a,b)
        unknown=seconds(unknown_idle,a,b)
        warnings.append({
            "start":a.isoformat(),"end":b.isoformat(),
            "elapsed_minutes":round(elapsed/60,1),
            "observed_nonafk_minutes":round(observed/60,1),
            "approved_work_context_minutes":round(direct/60,1),
            "unverified_chat_minutes":round(contextual/60,1),
            "other_active_minutes":round(max(0,observed-direct-contextual)/60,1),
            "unknown_idle_minutes":round(unknown/60,1),
            "outgoing_external_actions":own_actions,
            "reason":("unpublished_approved_context" if direct>=profile.auto_min_work_minutes*60
                      else "remote_actions_without_measured_duration"
                      if observed<min_active_minutes*60 and own_actions>=2
                      else "unattributed_observed_activity"),
            "needs_human_review":True,
            "human_duration_verified":False,
            "billable":False,
        })
    return warnings


def scheduled_event_gaps(day:str,calendar:list[dict],remote:list[dict],profile,
                         now:dt.datetime,*,min_event_minutes:int=15,
                         min_missing_minutes:int=5):
    """Review completed timed calendar events not covered by Kimai timesheets.

    Calendar metadata is a *schedule*, not proof of attendance or work. Never
    create a timesheet from this output. Works without ActivityWatch, including
    days on which unrelated work is already registered in Kimai. No event
    titles, attendee addresses or calendar URLs enter the report.
    """
    if now.tzinfo is None:
        raise ValueError("Aware now required")
    local_now=now.astimezone(profile.zone)
    date=dt.date.fromisoformat(day)
    floor=dt.datetime.combine(date,dt.time.min,tzinfo=profile.zone)
    ceiling=dt.datetime.combine(date+dt.timedelta(days=1),dt.time.min,
                                tzinfo=profile.zone)
    if floor>=local_now:
        return []

    recorded=[]
    for row in remote:
        try:
            a=_aware(row["begin"])
            b=_aware(row["end"])
            if a.tzinfo is None or b.tzinfo is None:
                continue
            a=max(floor,a.astimezone(profile.zone))
            b=min(ceiling,b.astimezone(profile.zone))
            if a<b:
                recorded.append((a,b))
        except (KeyError,ValueError,AttributeError,TypeError,OverflowError):
            continue
    recorded=union(recorded)

    warnings=[]
    seen=set()
    candidate_categories={
        "work_candidate","work_event_latinoware",
        "possible_meeting_unclassified","unclassified_not_work_proof",
    }
    for item in calendar:
        if not isinstance(item,dict) or item.get("all_day") is not False:
            continue
        if item.get("category") not in candidate_categories:
            continue
        if item.get("scheduled_only") is False:
            continue
        try:
            a=_aware(item["start_local"])
            b=_aware(item["end_local"])
            if a.tzinfo is None or b.tzinfo is None:
                continue
            a=a.astimezone(profile.zone)
            b=b.astimezone(profile.zone)
        except (KeyError,ValueError,AttributeError,TypeError,OverflowError):
            continue
        # Do not create alerts for invitations in progress or in the future.
        if b<=a or b>local_now:
            continue
        start=max(a,floor)
        end=min(b,ceiling)
        if start>=end or (end-start).total_seconds()<min_event_minutes*60:
            continue
        # Repeated/shared copies of the same slot are one review item.
        key=(start,end)
        if key in seen:
            continue
        seen.add(key)
        holes=subtract([(start,end)],recorded)
        remaining=sum((y-x).total_seconds() for x,y in holes)
        if remaining<min_missing_minutes*60:
            continue
        scheduled=(end-start).total_seconds()
        warnings.append({
            "day":day,"start":start.isoformat(),"end":end.isoformat(),
            "category":item["category"],
            "scheduled_minutes":round(scheduled/60,1),
            "uncovered_schedule_minutes":round(remaining/60,1),
            "recorded_overlap_minutes":round((scheduled-remaining)/60,1),
            "uncovered_intervals":[{"start":x.isoformat(),"end":y.isoformat()}
                                   for x,y in holes],
            "reason":"scheduled_event_not_fully_covered_by_kimai",
            "source":"calendar_schedule_not_attendance_proof",
            "scheduled_only":True,"attendance_verified":False,
            "hours_inferred":0,"requires_human_review":True,
            "billable":False,"payroll_approved":False,
        })
    return sorted(warnings,key=lambda row:(row["start"],row["end"]))


def missing_expected_workday(profile,day:str,remote:list,now:dt.datetime):
    """Flag a completed weekday with zero Kimai entries, even without AW.

    A stopped/offline computer cannot be interpreted as zero human work.
    This is a REVIEW warning, not a request to invent working hours.
    The worker can opt out or configure a different working pattern.
    """
    date=dt.date.fromisoformat(day)
    today=now.astimezone(profile.zone).date()
    if (profile.alert_on_unrecorded_weekdays is not True
        or date.weekday()>=5 or date>=today):
        return []
    for row in remote:
        try:
            if _aware(row["begin"]).astimezone(profile.zone).date()==date:
                return []
        except (KeyError,ValueError,TypeError,AttributeError,OverflowError):
            continue
    return [{
        "day":day,
        "reason":"completed_weekday_without_kimai_timesheets",
        "source":"expected_workday_policy_not_attendance_proof",
        "activitywatch_may_be_offline":True,
        "hours_inferred":0,
        "requires_human_review":True,
        "payroll_approved":False,
    }]
