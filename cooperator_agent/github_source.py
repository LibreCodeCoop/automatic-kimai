"""Optional GitHub metadata only: events are anchors, not human work time."""
from __future__ import annotations
import datetime as dt
import hashlib
import json

import requests
from kimai_client import save_private_json
from .profile import Profile


def collect_github(profile:Profile,credentials:dict):
    token=credentials.get("GITHUB_TOKEN","")
    if not token:
        # No filesystem access to a user's gh CLI configuration required.
        return {"status":"not_configured","events":0}
    session=requests.Session()
    session.trust_env=False
    h={"Accept":"application/vnd.github+json",
       "Authorization":"Bearer "+token,
       "X-GitHub-Api-Version":"2022-11-28"}
    def get(path):
        r=session.get("https://api.github.com/"+path,headers=h,
                      timeout=(3.0,15.0),allow_redirects=False)
        if r.status_code!=200 or len(r.content)>8_000_000:
            raise RuntimeError("GitHub read-only API failed")
        return r.json()
    me=get("user")
    login=me.get("login","")
    if not login:raise RuntimeError("GitHub identity invalid")
    from urllib.parse import quote
    recent=get("users/"+quote(login,safe="")+"/events?per_page=100")
    observations=[]
    for e in recent:
        date=e.get("created_at")
        if not isinstance(date,str) or not date.endswith("Z"):continue
        repo=(e.get("repo") or {}).get("name","")
        observations.append({
            "timestamp_utc":date,
            "kind":str(e.get("type") or "")[:64],
            "repo_hash":hashlib.sha256(repo.encode()).hexdigest()[:18],
            "event_id":str(e.get("id") or "")[:50],
            "human_duration_verified":False,
        })
    save_private_json(profile.cache_dir/"github_context.json",{
        "fetched_at":dt.datetime.now(dt.timezone.utc).isoformat(),
        "user_hash":hashlib.sha256(login.encode()).hexdigest()[:18],
        "events":observations,
        "privacy":"No titles, messages, commit bodies, URLs or repo names",
        "eligible_for_sync":False,
    })
    return {"status":"ok","events":len(observations)}
