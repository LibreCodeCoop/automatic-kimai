"""Suggest reviewable intervals from measured non-AFK evidence.

These are NOT measured work hours or automatic write candidates.
Every user's confirmation must specify actual worked start/end. Short
gaps/AFK blocks remain distinct; actions on remote devices are points,
not duration. Only after explicit user confirmation can a manual preview
be sent to Kimai.
"""
from __future__ import annotations
import datetime as dt

from .coverage import _aware
from .segment import classify,seconds,subtract,union,snapend,snapstart


def reviewable_intervals(day,events,remote,profile,now,calendar=(),
                         action_anchors=(),*,min_observed_seconds=5*60):
    date=dt.date.fromisoformat(day)
    floor=dt.datetime.combine(date,dt.time.min,tzinfo=profile.zone)
    ceiling=min(floor+dt.timedelta(days=1),now.astimezone(profile.zone))
    rows=[]
    blocks=[];barriers=[]
    for e in events:
        try:
            a=max(floor,_aware(e["start"]).astimezone(profile.zone))
            b=min(ceiling,_aware(e["end"]).astimezone(profile.zone))
            if a>=b:continue
            category=classify(e,profile)
            rows.append((a,b,category))
            if category=="private" or e.get("idle_status_known") is not True:
                barriers.append((a,b))
            elif category=="afk":
                blocks.append((a,b))
        except (ValueError,AttributeError,TypeError,KeyError,OverflowError):
            continue
    barriers.extend((a,b) for a,b in union(blocks)
                    if b-a>=dt.timedelta(minutes=profile.long_idle_minutes))
    for ev in calendar:
        if ev.get("date")!=day or ev.get("all_day"):continue
        if ev.get("category") not in ("personal_excluded","calendar_declined_excluded"):
            continue
        try:
            a=max(floor,_aware(ev["start_local"]).astimezone(profile.zone))
            b=min(ceiling,_aware(ev["end_local"]).astimezone(profile.zone))
            if a<b:barriers.append((a,b))
        except (KeyError,ValueError,TypeError,OverflowError,AttributeError):
            continue
    barriers=union(barriers)
    observations=union(subtract(
        [(a,b) for a,b,k in rows if k in ("work","chat","other")],barriers))
    groups=[]
    for a,b in observations:
        if (not groups
            or (a-groups[-1][1])>dt.timedelta(minutes=profile.max_bridge_minutes)
            or b-groups[-1][0]>dt.timedelta(hours=profile.max_session_hours)
            or any(x<a and y>groups[-1][1] for x,y in barriers)):
            groups.append([a,b])
        else:
            groups[-1][1]=max(groups[-1][1],b)
    recorded=[]
    for item in remote:
        try:
            if not item.get("begin") or not item.get("end"):continue
            a=max(floor,_aware(item["begin"]).astimezone(profile.zone))
            b=min(ceiling,_aware(item["end"]).astimezone(profile.zone))
            if a<b:recorded.append((a,b))
        except (KeyError,ValueError,TypeError,AttributeError,OverflowError):
            continue
    excluded=union(recorded+barriers)
    actions=sorted(t.astimezone(profile.zone) for t in action_anchors
                   if floor<=t.astimezone(profile.zone)<ceiling)
    result=[]
    for original in groups:
        for a,b in subtract([tuple(original)],excluded):
            a,b=snapstart(a),snapend(b)
            if a>=b:continue
            observed=seconds(observations,a,b)
            if observed<min_observed_seconds:continue
            categories={k:seconds([(x,y) for x,y,c in rows if c==k],a,b)
                        for k in ("work","chat","other")}
            outgoing=sum(a<=t<b for t in actions)
            result.append({
                "day":day,"start":a.isoformat(),"end":b.isoformat(),
                "observed_nonafk_minutes":round(observed/60,1),
                "approved_work_context_minutes":round(categories["work"]/60,1),
                "unverified_chat_minutes":round(categories["chat"]/60,1),
                "other_active_minutes":round(categories["other"]/60,1),
                "outgoing_external_actions":outgoing,
                "includes_unobserved_minutes":
                    observed<(b-a).total_seconds()-60,
                "requires_explicit_human_confirmation":True,
                "counts_as_payable_hours":False,
                "reason":"active_desktop_context_not_yet_recorded",
            })
    return result
