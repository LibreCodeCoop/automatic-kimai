"""Account-scoped Nextcloud CalDAV and Talk metadata readers.

Only calendars within the configured user's DAV path, HTTPS without redirects.
Summaries/message bodies/room titles are never saved. Scheduled meetings alone
must not count as attended or billable working time.
"""
from __future__ import annotations
import datetime as dt
import hashlib
import re
import urllib.parse
import xml.etree.ElementTree as ET
from pathlib import Path
from zoneinfo import ZoneInfo

import requests
from kimai_client import save_private_json
from .profile import Profile

DAV={"d":"DAV:","c":"urn:ietf:params:xml:ns:caldav"}
PERSONAL=re.compile(r"pessoal|dentista|medic|familia|treino|academia|karat[eê]|consulta",re.I)

def _base(url:str):
    parts=urllib.parse.urlsplit(str(url).rstrip("/"))
    if parts.scheme!="https" or not parts.hostname or parts.username or parts.password or parts.query or parts.fragment:
        raise ValueError("Nextcloud URL must be a simple HTTPS origin")
    if parts.path not in ("","/"):
        raise ValueError("Nextcloud base URL should not contain a private route")
    return url.rstrip("/")

def _request(session,method,url,*,data=None,headers=None):
    resp=session.request(method,url,data=data,headers=headers,
                         timeout=(3.0,18.0),allow_redirects=False)
    if resp.status_code!=207 or len(resp.content)>5_000_000:
        raise RuntimeError("Nextcloud CalDAV returned error or oversized response")
    return ET.fromstring(resp.content)

def _events(ics:str,zone,from_date=None,until_date=None):
    """VEVENT intervals, with bounded RRULE and EXDATE; fail closed otherwise."""
    unfolded=ics.replace("\r\n","\n").replace("\r","\n")
    unfolded=re.sub(r"\n[ \t]","",unfolded)
    result=[]
    blocks=re.findall(r"BEGIN:VEVENT\r?\n(.*?)\r?\nEND:VEVENT",unfolded,re.S)
    for block in blocks:
        fields={}
        for line in block.split("\n"):
            if ":" not in line:continue
            key,value=line.split(":",1)
            # Keep TZID's original case: IANA zone names are case-sensitive.
            fields[key]=value.strip()
        if any(k.startswith(("RECURRENCE-ID","RDATE")) for k in fields):
            # Moved instances and RDATE need override processing. Do not
            # silently omit a personal event and then auto-publish work.
            raise RuntimeError("Calendar exceptions/RDATE require review")
        def parse(prefix):
            for k,v in fields.items():
                if not k.startswith(prefix):continue
                if re.fullmatch(r"\d{8}",v):
                    return dt.datetime.strptime(v,"%Y%m%d").replace(tzinfo=zone),True
                try:
                    x=dt.datetime.strptime(v,"%Y%m%dT%H%M%S"+("Z" if v.endswith("Z") else ""))
                except ValueError:
                    continue
                if v.endswith("Z"):
                    return x.replace(tzinfo=dt.timezone.utc).astimezone(zone),False
                match=re.search(r"TZID=([^;:]+)",k)
                try:tz=ZoneInfo(match.group(1)) if match else zone
                except Exception:
                    raise RuntimeError("Unsupported calendar timezone") from None
                return x.replace(tzinfo=tz).astimezone(zone),False
            return None,False
        a,all_day=parse("DTSTART")
        b,_=parse("DTEND")
        if a and b and a<b:
            title=next((v for k,v in fields.items() if k.startswith("SUMMARY")),"")
            label="personal_excluded" if PERSONAL.search(title) else "possible_meeting_unclassified"
            if str(fields.get("STATUS","")).upper()=="CANCELLED":
                continue
            rule=next((v for k,v in fields.items() if k.startswith("RRULE")),None)
            occurrence_starts=[a]
            if rule:
                if from_date is None or until_date is None:
                    raise RuntimeError("Bounded calendar recurrence window required")
                from dateutil.rrule import rrulestr
                try:
                    recurrence=rrulestr(rule,dtstart=a)
                    window_a=dt.datetime.combine(from_date-dt.timedelta(days=2),
                                                 dt.time.min,tzinfo=zone)
                    window_b=dt.datetime.combine(until_date+dt.timedelta(days=1),
                                                 dt.time.min,tzinfo=zone)
                    occurrence_starts=recurrence.between(window_a,window_b,inc=True)
                except (ValueError,TypeError):
                    raise RuntimeError("Calendar RRULE unsupported") from None
                if len(occurrence_starts)>500:
                    raise RuntimeError("Calendar RRULE expands too many instances")
            excluded=set()
            for key,value in fields.items():
                if key.startswith("EXDATE"):
                    if re.search(r"TZID=([^;:]+)",key):
                        zone_name=re.search(r"TZID=([^;:]+)",key).group(1)
                        try:exzone=ZoneInfo(zone_name)
                        except Exception:
                            raise RuntimeError("Unknown EXDATE timezone") from None
                    else:
                        exzone=zone
                    for literal in value.split(","):
                        if literal.endswith("Z"):
                            when=dt.datetime.strptime(literal,"%Y%m%dT%H%M%SZ").replace(
                                tzinfo=dt.timezone.utc)
                        elif re.fullmatch(r"\d{8}",literal):
                            when=dt.datetime.strptime(literal,"%Y%m%d").replace(tzinfo=exzone)
                        else:
                            when=dt.datetime.strptime(literal,"%Y%m%dT%H%M%S").replace(tzinfo=exzone)
                        excluded.add(when.astimezone(dt.timezone.utc))
            duration=b-a
            for occ in occurrence_starts:
                if occ.astimezone(dt.timezone.utc) in excluded:
                    continue
                if from_date and until_date:
                    if occ.date()<from_date-dt.timedelta(days=1) or occ.date()>until_date:
                        continue
                result.append({"start_local":occ.isoformat(),
                               "end_local":(occ+duration).isoformat(),
                               "all_day":all_day,"category":label})
    return result

def collect_calendar(profile:Profile,credentials:dict,*,now=None):
    base=_base(credentials["NEXTCLOUD_URL"])
    username=credentials["NEXTCLOUD_USERNAME"]
    password=credentials["NEXTCLOUD_APP_PASSWORD"]
    if not username or not password or any(x in username for x in ("/","\\","\n")):
        raise ValueError("Invalid Nextcloud user credentials")
    session=requests.Session()
    session.trust_env=False
    session.auth=(username,password)
    root=base+"/remote.php/dav/calendars/"+urllib.parse.quote(username,safe="")+"/"
    find=(
        '<d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
        '<d:prop><d:displayname/><c:supported-calendar-component-set/></d:prop></d:propfind>')
    calendars=_request(session,"PROPFIND",root,data=find,headers={"Depth":"1",
                           "Content-Type":"application/xml; charset=utf-8"})
    calendar_paths=[]
    for item in calendars.findall("d:response",DAV):
        href=item.findtext("d:href",default="",namespaces=DAV)
        if not item.findall(".//c:comp[@name='VEVENT']",DAV):continue
        url=urllib.parse.urljoin(base+"/",href)
        if url.startswith(root) and url.endswith("/"):
            calendar_paths.append(url)
    if len(calendar_paths)>30:raise RuntimeError("Too many Nextcloud calendars")
    today=(now or dt.datetime.now(profile.zone)).astimezone(profile.zone).date()
    from_day=today-dt.timedelta(days=profile.lookback_days-1)
    until=today+dt.timedelta(days=2)
    query=(
        '<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
        '<d:prop><c:calendar-data/></d:prop><c:filter><c:comp-filter name="VCALENDAR">'
        '<c:comp-filter name="VEVENT"><c:time-range start="'+
        from_day.strftime("%Y%m%d")+"T000000Z"+'" end="'+
        until.strftime("%Y%m%d")+"T000000Z"+'"/></c:comp-filter></c:comp-filter>'
        '</c:filter></c:calendar-query>')
    events=[]
    for cal in calendar_paths:
        result=_request(session,"REPORT",cal,data=query,headers={"Depth":"1",
                   "Content-Type":"application/xml; charset=utf-8"})
        for node in result.findall(".//c:calendar-data",DAV):
            events.extend(_events(node.text or "",profile.zone,from_day,until))
    unique={}
    for x in events:
        day=dt.datetime.fromisoformat(x["start_local"]).astimezone(profile.zone).date()
        if from_day<=day<=until:
            x["date"]=day.isoformat()
            x["rsvp_for_worker"]="not_verified"
            x["scheduled_only"]=True
            unique[(x["start_local"],x["end_local"],x["category"])]=x
    final=list(unique.values())
    save_private_json(profile.cache_dir/"calendar_work_context.json",{
        "generated_utc":dt.datetime.now(dt.timezone.utc).isoformat(),
        "events":final,"calendars_scanned":len(calendar_paths),
        "notes":"No summaries stored; bounded RRULE/EXDATE expanded; unsupported overrides fail closed",
    })
    return {"calendar_events":len(final),"calendars_scanned":len(calendar_paths)}

def collect_talk(profile:Profile,credentials:dict):
    base=_base(credentials["NEXTCLOUD_URL"])
    username=credentials["NEXTCLOUD_USERNAME"]
    session=requests.Session()
    session.trust_env=False
    session.auth=(username,credentials["NEXTCLOUD_APP_PASSWORD"])
    headers={"OCS-APIRequest":"true","Accept":"application/json"}
    def ocs(route,parameters=None):
        params={"format":"json",**(parameters or {})}
        response=session.get(base+route,params=params,
                             headers=headers,timeout=(3.0,15.0),
                             allow_redirects=False)
        if response.status_code!=200 or len(response.content)>5_000_000:
            raise RuntimeError("Nextcloud Talk read-only OCS API failed")
        obj=response.json().get("ocs",{})
        if int(obj.get("meta",{}).get("statuscode",200)) not in (100,200):
            raise RuntimeError("Nextcloud OCS returned error")
        return obj.get("data")
    identity=ocs("/ocs/v2.php/cloud/user")
    if not isinstance(identity,dict) or identity.get("id")!=username:
        raise RuntimeError("Nextcloud user identity differs from configured account")
    rooms=ocs("/ocs/v2.php/apps/spreed/api/v4/room",
              {"noStatusUpdate":1})
    if not isinstance(rooms,list):
        raise RuntimeError("Nextcloud Talk room list unavailable")
    # Metadata only, with bounded own-message timestamps. No read markers
    # or presence modifications, no chat bodies, titles or participants saved.
    safe=[]
    own_actions=[]
    warnings=0
    cutoff=dt.datetime.now(dt.timezone.utc)-dt.timedelta(days=profile.lookback_days)
    for room in rooms[:30]:
        token=str(room.get("token",""))
        if not re.fullmatch(r"[A-Za-z0-9]{5,100}",token):continue
        activity=room.get("lastActivity")
        safe.append({
            "room_id_hash":hashlib.sha256(token.encode()).hexdigest()[:24],
            "last_activity_timestamp":activity,
            "has_call":bool(room.get("hasCall",False)),
            "counts_as_human_work":False,
        })
        if type(activity) is int and activity<cutoff.timestamp():
            continue
        try:
            rows=ocs("/ocs/v2.php/apps/spreed/api/v1/chat/"+token,{
                "lookIntoFuture":0,"limit":100,"setReadMarker":0,
                "noStatusUpdate":1,"markNotificationsAsRead":0})
            if not isinstance(rows,list):raise RuntimeError("Talk chat response malformed")
            for message in rows:
                if not isinstance(message,dict):continue
                moment=message.get("timestamp")
                if (type(moment) is int and moment>0 and
                    str(message.get("actorId") or "")==username):
                    when=dt.datetime.fromtimestamp(moment,dt.timezone.utc)
                    if when>=cutoff:
                        own_actions.append({"utc":when.isoformat(),
                                            "source":"nextcloud_talk",
                                            "verified_human_duration":False})
        except (requests.RequestException,RuntimeError,ValueError,TypeError,OverflowError):
            warnings+=1
            continue
    save_private_json(profile.cache_dir/"nextcloud_talk_context.json",{
        "fetched_at":dt.datetime.now(dt.timezone.utc).isoformat(),
        "rooms":safe,"own_actions":own_actions,
        "rooms_unavailable":warnings,
        "privacy":"No room titles, message bodies or participant identities persisted",
        "eligible_for_sync":False,
    })
    return {"talk_rooms":len(safe),"own_action_timestamps":len(own_actions),
            "rooms_unavailable":warnings}
