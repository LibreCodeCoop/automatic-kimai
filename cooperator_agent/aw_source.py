"""Generic ActivityWatch local API reader used in portable desktop builds.

Cross-platform: ActivityWatch emits currentwindow, afkstatus and web.tab.*
buckets on Windows, macOS and Linux (watcher availability varies).
No raw window title, private URL, chat body or credentials are serialized.
"""
from __future__ import annotations
from dataclasses import dataclass
import datetime as dt
import hashlib
import heapq
import re
import urllib.parse

import requests


class ActivityWatchError(RuntimeError):
    pass

@dataclass(frozen=True)
class Interval:
    start:dt.datetime
    end:dt.datetime
    data:dict

class AWReader:
    def __init__(self,base_url="http://127.0.0.1:5600",session=None):
        url=urllib.parse.urlsplit(base_url.rstrip("/"))
        if (url.scheme!="http" or url.hostname not in ("127.0.0.1","localhost","::1")
            or url.username or url.password or url.query or url.fragment):
            raise ValueError("ActivityWatch must be a local HTTP server")
        self.base_url=base_url.rstrip("/")+"/api/0"
        self.session=session or requests.Session()
        self.session.trust_env=False

    def _get(self,resource,params=None):
        if ".." in resource or not resource.startswith("/buckets/"):
            raise ValueError("Invalid ActivityWatch API route")
        try:
            response=self.session.get(self.base_url+resource,
                                      params=params,timeout=(2.5,12.0),
                                      allow_redirects=False)
        except requests.RequestException:
            raise ActivityWatchError("ActivityWatch unavailable") from None
        if response.status_code!=200 or len(response.content)>20_000_000:
            raise ActivityWatchError("ActivityWatch returned non-200/oversized response")
        try:return response.json()
        except ValueError:raise ActivityWatchError("ActivityWatch returned invalid JSON") from None

    def buckets(self):
        result=self._get("/buckets/")
        if not isinstance(result,dict):raise ActivityWatchError("Invalid ActivityWatch buckets")
        return result

    def events(self,bucket,start,end):
        if not re.fullmatch(r"[A-Za-z0-9_.-]{1,150}",bucket):
            raise ValueError("Unsafe ActivityWatch bucket ID")
        raw=self._get("/buckets/"+urllib.parse.quote(bucket,safe="")+"/events",
                      {"start":start.isoformat(),"end":end.isoformat(),"limit":25000})
        if not isinstance(raw,list) or len(raw)>=25000:
            raise ActivityWatchError("ActivityWatch events malformed/truncated")
        events=[]
        for r in raw:
            try:
                a=dt.datetime.fromisoformat(r["timestamp"].replace("Z","+00:00"))
                seconds=float(r["duration"])
                b=a+dt.timedelta(seconds=seconds)
                a=max(start,a)
                b=min(end,b)
                if 0<seconds<7*86400 and a<b and isinstance(r.get("data"),dict):
                    events.append(Interval(a,b,r["data"]))
            except (KeyError,ValueError,TypeError,OverflowError):
                continue
        return sorted(events,key=lambda e:e.start)

class Cursor:
    def __init__(self,events):
        self.events=sorted(events,key=lambda e:e.start)
        self.index=0
        self.active=[]

    def at(self,instant):
        while self.index<len(self.events) and self.events[self.index].start<=instant:
            i=self.index
            event=self.events[i]
            heapq.heappush(self.active,(-event.start.timestamp(),i,event))
            self.index+=1
        while self.active and self.active[0][2].end<=instant:
            heapq.heappop(self.active)
        return self.active[0][2] if self.active else None

def url_kind(raw_url:str,approved_domains=()):
    try:
        url=urllib.parse.urlsplit(str(raw_url))
    except ValueError:
        return "","other"
    if url.scheme!="https" or not url.hostname or url.username or url.password:
        return "","other"
    host=url.hostname.casefold()
    if host in ("chatgpt.com","chat.openai.com"):
        return "https://chatgpt.com","chat"
    if host in ("claude.ai","www.claude.ai"):
        return "https://claude.ai","chat"
    if host in ("slides.com","www.slides.com"):
        return "https://slides.com","work_site"
    if host in approved_domains:
        # A worker-approved exact host only. Never persist account or page path.
        return "https://"+host,"work_site"
    if host=="github.com":
        parts=[p for p in url.path.split("/") if p]
        if (len(parts)>=2 and all(re.fullmatch(r"[A-Za-z0-9_.-]{1,100}",x) for x in parts[:2])
            and all(x not in (".","..") for x in parts[:2])):
            path="/".join(parts[:2])
            if len(parts)>=4 and parts[2] in ("pull","issues") and parts[3].isdigit():
                path+="/"+parts[2]+"/"+parts[3][:8]
            return "https://github.com/"+path,"github"
    return "","other"

def collect(reader:AWReader,start:dt.datetime,end:dt.datetime,*,approved_domains=()):
    if start.tzinfo is None or end.tzinfo is None or start>=end:
        raise ValueError("ActivityWatch interval must be aware and increasing")
    source=reader.buckets()
    data={"window":[],"afk":[],"web":[]}
    buckets={}
    browser_present=False
    for ident,meta in source.items():
        if not isinstance(meta,dict):continue
        kind=str(meta.get("type","")).casefold()
        if kind=="currentwindow":key="window"
        elif kind=="afkstatus":key="afk"
        elif kind.startswith("web.tab."):
            key="web"
            browser_present=True
        else:continue
        rows=reader.events(ident,start,end)
        data[key].extend(rows)
        buckets[ident]=len(rows)
    if not data["window"] or not data["afk"]:
        raise ActivityWatchError("Desktop window or AFK watcher unavailable")
    cursor_window,cursor_afk,cursor_web=(Cursor(data[k]) for k in ("window","afk","web"))
    times=sorted({t for group in data.values() for e in group for t in (e.start,e.end)})
    if len(times)>100_000:raise ActivityWatchError("ActivityWatch event boundary limit exceeded")
    output=[]
    for a,b in zip(times,times[1:]):
        if a>=b or (b-a).total_seconds()<1:continue
        middle=a+(b-a)/2
        win=cursor_window.at(middle)
        if win is None:continue
        afk=cursor_afk.at(middle)
        status=(afk.data.get("status") if afk else None)
        known=status in ("not-afk","afk")
        inactive=status=="afk"
        app=str(win.data.get("app") or "unknown")[:100]
        web=cursor_web.at(middle) if any(k in app.casefold() for k in
            ("firefox","chrome","chromium","edge","brave","safari","vivaldi","zen")) else None
        private=bool(web and (web.data.get("incognito") or web.data.get("private")))
        safe,kind=url_kind(web.data.get("url",""),approved_domains) if web else ("","none")
        if inactive or private:
            safe,kind="","none"
        chat=None
        if kind=="chat" and not inactive and known:
            # No conversation path, user messages, or raw title is exported.
            raw=urllib.parse.urlsplit(str(web.data.get("url") or ""))
            match=re.fullmatch(r"/(?:c|chat)/([A-Za-z0-9-]{8,150})",raw.path)
            chat={
                "app_context":"chatgpt" if safe.endswith("chatgpt.com") else "claude_browser",
                "conversation_fingerprint":(
                    hashlib.sha256(match.group(1).encode()).hexdigest()[:20]
                    if match else None),
                "topic_hints":[],
                "contains_message_text":False,
            }
        event={"start":a.isoformat(),"end":b.isoformat(),"app":app,
               "url_kind":kind,"url":safe,"afk":inactive,
               "idle_status_known":known,"private":private,
               "source":"activitywatch","chat_context":chat,
               "messenger_hint":None,"afk_reading_hint":None,"talk_context":None}
        if (output and output[-1]["end"]==event["start"] and all(
            output[-1][k]==v for k,v in event.items() if k not in ("start","end"))):
            output[-1]["end"]=event["end"]
        else:
            output.append(event)
    for e in output:
        e["observed_seconds"]=(dt.datetime.fromisoformat(e["end"])-
                               dt.datetime.fromisoformat(e["start"])).total_seconds()
        material="|".join([e["start"],e["end"],e["app"],e["url_kind"],
                           str(e["afk"]),str(e["private"])])
        e["source_id"]=hashlib.sha256(material.encode()).hexdigest()[:20]
    return output,{
        "buckets_seen":len(source),"active_buckets":buckets,
        "window_events":len(data["window"]),"afk_events":len(data["afk"]),
        "browser_events":len(data["web"]),"sanitized_blocks":len(output),
        "browser_extension_detected":browser_present,
    }
