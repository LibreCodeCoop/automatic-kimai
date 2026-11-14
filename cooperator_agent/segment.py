"""Portable AW segmentation without user-specific IDs or credentials.

Candidate envelopes are not certified labor duration. Long AFK and personal
calendar exclusions are hard boundaries. No network or private message body.
"""
from __future__ import annotations
from dataclasses import dataclass
import datetime as dt
import hashlib,json
from urllib.parse import urlsplit
from .profile import Profile

@dataclass(frozen=True)
class Window:
    id: str
    date: str
    start: dt.datetime
    end: dt.datetime
    work_seconds: int
    chat_unknown_seconds: int
    uncovered_seconds: int
    ongoing: bool
    topics: tuple[str,...]
    evidence_digest: str

    def to_json(self):
        return {"id":self.id,"date":self.date,"start":self.start.isoformat(),
                "end":self.end.isoformat(),"work_seconds":self.work_seconds,
                "chat_unknown_seconds":self.chat_unknown_seconds,
                "uncovered_seconds":self.uncovered_seconds,"ongoing":self.ongoing,
                "topics":list(self.topics),"evidence_digest":self.evidence_digest,
                "human_duration_verified":False,"billable":False}

def classify(e,profile):
    # Current window alone cannot establish non-AFK status. This is
    # especially important when the computer is unlocked for remote MCP.
    if e.get("private"):return "private"
    if e.get("idle_status_known") is False:return "other"
    if e.get("afk"):return "afk"
    if e.get("url_kind")=="github":return "work"
    # Workplace applications and chat groups may be corroborating human
    # evidence ONLY when the individual worker explicitly approves them.
    # Do not classify every Telegram window or every unknown website as work.
    if e.get("url_kind") in ("internal_domain","work_site"):
        try:host=urlsplit(str(e.get("url") or "")).hostname
        except ValueError:host=None
        if host and host in profile.work_domains:
            return "work"
    hint=e.get("messenger_hint")
    if (isinstance(hint,dict) and
        hint.get("client_hint") in profile.work_messenger_labels):
        return "work"
    if e.get("url_kind")=="chat" or e.get("chat_context"):
        topics=(e.get("chat_context") or {}).get("topic_hints") or []
        if any(h.lower() in str(t).lower() for h in profile.work_topic_hints for t in topics):
            return "work"
        return "chat"
    return "other"

def union(spans):
    result=[]
    for a,b in sorted(spans):
        if not result or a>result[-1][1]:result.append((a,b))
        elif b>result[-1][1]:result[-1]=(result[-1][0],b)
    return result

def subtract(spans,barriers):
    result=list(spans)
    for x,y in barriers:
        nxt=[]
        for a,b in result:
            if y<=a or x>=b:nxt.append((a,b))
            else:
                if a<x:nxt.append((a,x))
                if y<b:nxt.append((y,b))
        result=nxt
    return sorted(result)

def seconds(spans,start,end):
    return int(sum((min(b,end)-max(a,start)).total_seconds()
                   for a,b in union((max(a,start),min(b,end)) for a,b in spans
                                     if a<end and start<b)))

def snapstart(value):
    floor=value.replace(second=0,microsecond=0)
    return floor+(dt.timedelta(minutes=1) if floor<value else dt.timedelta())

def snapend(value):
    return value.replace(second=0,microsecond=0)

def segment(day:str,events:list[dict],calendar:list[dict],profile:Profile,now:dt.datetime):
    profile.validate()
    if now.tzinfo is None:raise ValueError("now must be aware")
    date=dt.date.fromisoformat(day)
    floor=dt.datetime.combine(date,dt.time.min,tzinfo=profile.zone)
    ceiling=min(floor+dt.timedelta(days=1),now.astimezone(profile.zone))
    rows=[]
    for e in events:
        try:
            a=dt.datetime.fromisoformat(e["start"].replace("Z","+00:00")).astimezone(profile.zone)
            b=dt.datetime.fromisoformat(e["end"].replace("Z","+00:00")).astimezone(profile.zone)
            kind=classify(e,profile)
        except (ValueError,KeyError,AttributeError,TypeError,OverflowError):continue
        a,b=max(a,floor),min(b,ceiling)
        if a<b:rows.append((a,b,kind,e))
    # AW splits a continuous AFK interval each time the foreground window
    # changes. Join adjacent AFK fragments BEFORE applying the threshold;
    # otherwise ten minutes away can appear as many tiny ignored fragments.
    afk_blocks=union((a,b) for a,b,k,_ in rows if k=="afk")
    barriers=[(a,b) for a,b in afk_blocks
              if b-a>=dt.timedelta(minutes=profile.long_idle_minutes)]
    barriers.extend((a,b) for a,b,k,e in rows
                    if k=="private" or e.get("idle_status_known") is False)
    for ev in calendar:
        if ev.get("date")!=day or ev.get("all_day") or ev.get("category") not in (
            "personal_excluded","calendar_declined_excluded"):continue
        try:
            a=dt.datetime.fromisoformat(ev["start_local"].replace("Z","+00:00")).astimezone(profile.zone)
            b=dt.datetime.fromisoformat(ev["end_local"].replace("Z","+00:00")).astimezone(profile.zone)
        except (ValueError,TypeError,KeyError,AttributeError):continue
        if a<b:barriers.append((a,b))
    barriers=union(barriers)
    active=union(subtract([(a,b) for a,b,k,_ in rows if k in ("work","chat")],barriers))
    groups=[]
    for a,b in active:
        if (not groups or a-groups[-1][-1][1]>dt.timedelta(minutes=profile.max_bridge_minutes)
            or b-groups[-1][0][0]>dt.timedelta(hours=profile.max_session_hours)
            or any(x<a and y>groups[-1][-1][1] for x,y in barriers)):
            groups.append([(a,b)])
        else:groups[-1].append((a,b))
    output=[]
    for g in groups:
        a,b=snapstart(g[0][0]),snapend(g[-1][1])
        # ActivityWatch windows end at 23:59:59.999999 when split by day.
        # Do not discard the final minute of a genuinely active workday:
        # the half-open endpoint 00:00 of the next day is valid in Kimai.
        # Only round an observed boundary <1s from midnight, never assume
        # unobserved minutes or pull time from the following day into today.
        next_midnight=floor+dt.timedelta(days=1)
        if (g[-1][1]>=next_midnight-dt.timedelta(seconds=1)
            and b==next_midnight-dt.timedelta(minutes=1)):
            b=next_midnight
        if b-a<dt.timedelta(minutes=profile.min_session_minutes):continue
        if any(x<b and a<y for x,y in barriers):continue
        work=seconds([(x,y) for x,y,k,_ in rows if k=="work"],a,b)
        chat=seconds([(x,y) for x,y,k,_ in rows if k=="chat"],a,b)
        covered=seconds(g,a,b)
        if covered<profile.min_context_minutes*60:continue
        missing=max(0,int((b-a).total_seconds())-covered)
        topics=tuple(sorted({str(t) for x,y,k,e in rows if x<b and a<y
                             for t in (e.get("chat_context") or {}).get("topic_hints",[])
                             if isinstance(t,str) and len(t)<64})[:8])
        material={"start":a.isoformat(),"end":b.isoformat(),"work":work,
                  "chat":chat,"missing":missing,"topics":topics}
        evidence_digest=hashlib.sha256(json.dumps(material,sort_keys=True).encode()).hexdigest()
        anchor=a.replace(minute=(a.minute//15)*15,second=0,microsecond=0)
        ident=hashlib.sha256((profile.profile_id+"|"+day+"|"+anchor.isoformat()).encode()).hexdigest()[:24]
        ongoing=date==now.astimezone(profile.zone).date() and (
            now.astimezone(profile.zone)-g[-1][1]<dt.timedelta(minutes=profile.settle_minutes))
        output.append(Window(ident,day,a,b,work,chat,missing,ongoing,topics,evidence_digest))
    for a,b in zip(output,output[1:]):
        if a.end>b.start:raise ValueError("Proposed sessions overlap")
    return output
