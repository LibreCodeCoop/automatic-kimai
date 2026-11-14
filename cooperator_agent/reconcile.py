"""Multi-worker reconciliation: no hardcoded October IDs, no silent duplicates.

Plan first; optional nonbillable preview execution via explicitly enabled
worker mode. A prior remote snapshot must still match before any PATCH.
No automatic DELETE, begin-time adjustment, shrink, split or billable change.
"""
from __future__ import annotations
from dataclasses import dataclass
import datetime as dt
import hashlib,json,os,sqlite3
from pathlib import Path
import requests

from kimai_client import KimaiClient,KimaiAPIError,minimal_catalog
from .profile import Profile


def linked_id(value):
    return value.get("id") if isinstance(value,dict) else value


def post_once(api:KimaiClient,payload:dict):
    """One bounded Kimai POST, no redirects or retries, never log response bodies."""
    response=api.session.post(
        api.base_url+"/api/timesheets",
        json=payload,
        headers={"Authorization":"Bearer "+api.token,
                 "Accept":"application/json","Content-Type":"application/json",
                 "User-Agent":"automatic-kimai/cooperator-agent"},
        timeout=(3.0,18.0),allow_redirects=False)
    if response.status_code not in (200,201):
        raise RuntimeError("Kimai POST returned non-success status")
    if len(response.content)>1_000_000:
        raise RuntimeError("Kimai POST response oversized")
    try:row=response.json()
    except ValueError:raise RuntimeError("Kimai POST returned non-JSON response") from None
    if not isinstance(row,dict) or type(row.get("id")) is not int or row["id"]<1:
        raise RuntimeError("Kimai POST returned invalid ID")
    return row["id"]

from .segment import Window

def iso(value):
    if isinstance(value,str):
        return dt.datetime.fromisoformat(value.replace("Z","+00:00"))
    return value

def checksum(value):
    return hashlib.sha256(json.dumps(value,sort_keys=True,ensure_ascii=False).encode()).hexdigest()

def snapshot(remote):
    if not remote or not remote.get("end"):raise ValueError("Finished remote timesheet required")
    return {
        "id":int(remote["id"]),
        "begin":iso(remote["begin"]).astimezone(dt.timezone.utc).isoformat(),
        "end":iso(remote["end"]).astimezone(dt.timezone.utc).isoformat(),
        "user":linked_id(remote.get("user")),
        "project":linked_id(remote.get("project")),
        "activity":linked_id(remote.get("activity")),
        "billable":remote.get("billable"),
        "exported":remote.get("exported"),
        "description":remote.get("description"),
        "duration":remote.get("duration"),
    }

def remote_overlaps(remote,start,end,*,ignore=None):
    return [r["id"] for r in remote if r.get("id")!=ignore and r.get("begin")
            and iso(r["begin"])<end and
            (not r.get("end") or start<iso(r["end"]))]

def remote_day_list(client:KimaiClient,user_id:int,days:list[dt.date],zone):
    if not days:return []
    start=dt.datetime.combine(min(days),dt.time.min,tzinfo=zone)
    end=dt.datetime.combine(max(days),dt.time(23,59,59),tzinfo=zone)
    records=[]
    for page in range(1,21):
        rows,headers=client.get("/timesheets",{
            "user":user_id,"begin":start.strftime("%Y-%m-%dT%H:%M:%S"),
            "end":end.strftime("%Y-%m-%dT%H:%M:%S"),"page":page,"size":500
        })
        if not isinstance(rows,list):raise KimaiAPIError("Invalid timesheet collection")
        if any(linked_id(r.get("user")) not in (None,user_id) for r in rows):
            raise KimaiAPIError("Remote result includes another worker")
        records.extend(rows)
        pages=next((x for k,x in headers.items() if k.lower()=="x-total-pages"),"1")
        if page>=int(pages):return records
    raise KimaiAPIError("Pagination safety limit exceeded")


def valid_daily_interval(begin:dt.datetime,end:dt.datetime,zone)->bool:
    """Allow a session to end at *next* midnight, but not run into next day.

    This keeps one minute from disappearing at 23:59. Further work after
    midnight is assessed as an independent, separately reviewed session.
    """
    if begin.tzinfo is None or end.tzinfo is None:
        return False
    start=begin.astimezone(zone)
    stop=end.astimezone(zone)
    if stop<=start:
        return False
    if start.date()==stop.date():
        return True
    midnight=dt.datetime.combine(start.date()+dt.timedelta(days=1),
                                 dt.time.min,tzinfo=zone)
    return stop==midnight


@dataclass(frozen=True)
class Decision:
    action: str
    window: Window
    remote_id: int|None
    reason: str

    def to_json(self):
        return {"action":self.action,"session_id":self.window.id,
                "date":self.window.date,"begin":self.window.start.isoformat(),
                "end":self.window.end.isoformat(),"remote_id":self.remote_id,
                "reason":self.reason,"human_duration_verified":False,
                "billable":False}


def plan_one(window:Window,profile:Profile,remote:list[dict],known:dict|None,
             *,user_id:int):
    begin,end=window.start,window.end
    if (window.date!=begin.astimezone(profile.zone).date().isoformat() or
        not valid_daily_interval(begin,end,profile.zone)):
        return Decision("review",window,None,"invalid_interval")
    if window.ongoing and not profile.allow_snapshots:
        return Decision("hold",window,None,"session_still_running")
    relevant=[r for r in remote if r.get("begin")]
    if known is None:
        collisions=remote_overlaps(relevant,begin,end)
        if collisions:
            return Decision("review",window,None,"existing_remote_timesheet_overlap")
        return Decision("post",window,None,"new_nonbillable_internal_preview")
    ident=known["remote_id"]
    row=next((r for r in relevant if r.get("id")==ident),None)
    if row is None:
        return Decision("review",window,ident,"previously_managed_timesheet_missing")
    current=snapshot(row)
    if known["remote_snapshot"]!=current:
        return Decision("review",window,ident,"remote_was_edited_outside_agent")
    if (current["user"] not in (None,user_id) or
        current["project"]!=profile.project_id or
        current["activity"]!=profile.activity_id or
        current["billable"] is not False or
        current["exported"] is not False):
        return Decision("review",window,ident,"contract_or_export_guard")
    old_start,old_end=iso(row["begin"]),iso(row["end"])
    if begin!=old_start:
        return Decision("review",window,ident,"start_shift_requires_human")
    if end<old_end:
        return Decision("review",window,ident,"shrink_or_split_requires_human")
    if end==old_end:
        return Decision("unchanged",window,ident,"remote_matches_stable_evidence")
    if remote_overlaps(relevant,old_start,end,ignore=ident):
        return Decision("review",window,ident,"extension_would_overlap_other_timesheet")
    return Decision("patch",window,ident,"extend_same_record_preserve_description")


class Ledger:
    """One private durable ledger per worker. Intents always precede HTTP."""
    def __init__(self,path:Path,*,user_id:int,profile_id:str):
        self.path=Path(path)
        self.path.parent.mkdir(mode=0o700,parents=True,exist_ok=True)
        if self.path.is_symlink() or self.path.parent.is_symlink():
            raise ValueError("Refusing symlinked ledger")
        if os.name!="nt" and self.path.exists() and self.path.stat().st_mode & 0o077:
            raise ValueError("Private ledger must be chmod 600")
        if not self.path.exists():
            fd=os.open(self.path,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
            os.close(fd)
        self.db=sqlite3.connect(self.path,isolation_level=None,timeout=20)
        self.db.execute("PRAGMA busy_timeout=20000")
        self.db.execute("PRAGMA journal_mode=DELETE")
        self.db.execute("PRAGMA synchronous=FULL")
        self.db.execute("CREATE TABLE IF NOT EXISTS context (key TEXT PRIMARY KEY,value TEXT NOT NULL)")
        self.db.execute("""CREATE TABLE IF NOT EXISTS sessions (
            session_id TEXT PRIMARY KEY,remote_id INTEGER NOT NULL UNIQUE,
            remote_snapshot_json TEXT NOT NULL,evidence_digest TEXT NOT NULL,
            updated_at TEXT NOT NULL)""")
        self.db.execute("""CREATE TABLE IF NOT EXISTS intents (
            intent_id TEXT PRIMARY KEY,session_id TEXT NOT NULL,
            action TEXT NOT NULL,state TEXT NOT NULL,
            remote_id INTEGER,payload_json TEXT NOT NULL,
            updated_at TEXT NOT NULL)""")
        claim=checksum({"user":user_id,"profile":profile_id})
        previous=self.db.execute("SELECT value FROM context WHERE key='identity'").fetchone()
        if previous and previous[0]!=claim:
            self.db.close()
            raise ValueError("Ledger belongs to a different Kimai account/profile")
        self.db.execute("INSERT OR IGNORE INTO context VALUES ('identity',?)",(claim,))

    def close(self):
        self.db.close()

    def get(self,session_id):
        row=self.db.execute("SELECT remote_id,remote_snapshot_json FROM sessions WHERE session_id=?",
                            (session_id,)).fetchone()
        return {"remote_id":row[0],"remote_snapshot":json.loads(row[1])} if row else None

    def intent(self,key):
        row=self.db.execute("SELECT state,remote_id FROM intents WHERE intent_id=?",
                            (key,)).fetchone()
        return row

    def reserve(self,key,window,action,payload):
        now=dt.datetime.now(dt.timezone.utc).isoformat()
        self.db.execute("BEGIN IMMEDIATE")
        try:
            prior=self.intent(key)
            if prior is not None:raise RuntimeError("Intent already exists; no blind retry")
            self.db.execute("INSERT INTO intents VALUES (?,?,?,?,?,?,?)",
                            (key,window.id,action,"sending",None,
                             json.dumps({"kimai":payload,"evidence_digest":window.evidence_digest},sort_keys=True),now))
            self.db.execute("COMMIT")
        except BaseException:
            if self.db.in_transaction:self.db.execute("ROLLBACK")
            raise

    def pending(self):
        return [dict(zip(("key","session_id","action","state","remote_id","payload"),
                         (row[0],row[1],row[2],row[3],row[4],json.loads(row[5]))))
                for row in self.db.execute(
                    "SELECT intent_id,session_id,action,state,remote_id,payload_json "
                    "FROM intents WHERE state IN (\'sending\',\'unknown\') ORDER BY updated_at")]

    def recover_confirmed(self,intent,remote):
        """Only after unique, exact live GET. Never sends POST/PATCH."""
        now=dt.datetime.now(dt.timezone.utc).isoformat()
        snap=snapshot(remote)
        self.db.execute("BEGIN IMMEDIATE")
        try:
            self.db.execute("UPDATE intents SET state=\'confirmed\',remote_id=?,updated_at=? "
                            "WHERE intent_id=? AND state IN (\'unknown\',\'sending\')",
                            (snap["id"],now,intent["key"]))
            if self.db.execute("SELECT changes()").fetchone()[0]!=1:
                raise RuntimeError("Pending intent state changed")
            self.db.execute("""INSERT INTO sessions(session_id,remote_id,remote_snapshot_json,evidence_digest,updated_at)
                VALUES (?,?,?,?,?) ON CONFLICT(session_id) DO UPDATE SET
                remote_id=excluded.remote_id,remote_snapshot_json=excluded.remote_snapshot_json,
                evidence_digest=excluded.evidence_digest,updated_at=excluded.updated_at""",
                (intent["session_id"],snap["id"],json.dumps(snap,sort_keys=True),
                 intent["payload"]["evidence_digest"],now))
            self.db.execute("COMMIT")
        except BaseException:
            if self.db.in_transaction:self.db.execute("ROLLBACK")
            raise

    def mark_unknown(self,key,remote_id=None):
        self.db.execute("UPDATE intents SET state='unknown',remote_id=?,updated_at=? WHERE intent_id=? AND state='sending'",
                        (remote_id,dt.datetime.now(dt.timezone.utc).isoformat(),key))

    def confirm(self,key,window,remote):
        now=dt.datetime.now(dt.timezone.utc).isoformat()
        snap=snapshot(remote)
        self.db.execute("BEGIN IMMEDIATE")
        try:
            self.db.execute("UPDATE intents SET state='confirmed',remote_id=?,updated_at=? WHERE intent_id=? AND state='sending'",
                            (snap["id"],now,key))
            if self.db.execute("SELECT changes()").fetchone()[0]!=1:
                raise RuntimeError("Unexpected outbox state")
            self.db.execute("""INSERT INTO sessions(session_id,remote_id,remote_snapshot_json,evidence_digest,updated_at)
                VALUES (?,?,?,?,?) ON CONFLICT(session_id) DO UPDATE SET
                remote_id=excluded.remote_id,remote_snapshot_json=excluded.remote_snapshot_json,
                evidence_digest=excluded.evidence_digest,updated_at=excluded.updated_at""",
                (window.id,snap["id"],json.dumps(snap,sort_keys=True),window.evidence_digest,now))
            self.db.execute("COMMIT")
        except BaseException:
            if self.db.in_transaction:self.db.execute("ROLLBACK")
            raise


def description(window:Window):
    topics=[x for x in window.topics if x.strip()]
    if topics and window.id.startswith("self"):
        # A human-declared category is already a work description.
        # Avoid the redundant generic prefix, but never attach audit flags.
        return topics[0].rstrip(".")+"."
    if topics:
        return "Análise técnica e acompanhamento de atividades relacionadas a "+", ".join(topics[:3])+"."
    return "Análise e acompanhamento de atividades técnicas de desenvolvimento e infraestrutura."


def payload(window:Window,profile:Profile):
    if window.start.tzinfo is None or window.end.tzinfo is None:
        raise ValueError("Timezone-aware session required")
    start,end=window.start.astimezone(profile.zone),window.end.astimezone(profile.zone)
    if not valid_daily_interval(start,end,profile.zone):
        raise ValueError("Invalid local bounds")
    return {"begin":start.strftime("%Y-%m-%dT%H:%M:%S"),
            "end":end.strftime("%Y-%m-%dT%H:%M:%S"),
            "project":profile.project_id,"activity":profile.activity_id,
            "billable":False,"exported":False,"description":description(window)}


def verified(remote:dict|None,expected:dict,profile:Profile,user_id:int):
    if not remote or not remote.get("end"):return False
    a,b=iso(remote["begin"]).astimezone(profile.zone),iso(remote["end"]).astimezone(profile.zone)
    try:
        return (a.strftime("%Y-%m-%dT%H:%M:%S")==expected["begin"] and
            b.strftime("%Y-%m-%dT%H:%M:%S")==expected["end"] and
            remote["duration"]==int((b-a).total_seconds()) and
            linked_id(remote.get("user")) in (None,user_id) and
            linked_id(remote.get("project"))==profile.project_id and
            linked_id(remote.get("activity"))==profile.activity_id and
            remote.get("billable") is False and remote.get("exported") is False and
            remote.get("description")==expected["description"])
    except (TypeError,ValueError,KeyError):
        return False


def validate_catalog(client:KimaiClient,profile:Profile):
    catalog,warnings=minimal_catalog(client)
    if warnings:raise ValueError("Incomplete catalog")
    project=next((x for x in catalog["projects"] if x["id"]==profile.project_id),None)
    activity=next((x for x in catalog["activities"] if x["id"]==profile.activity_id),None)
    if not project or project.get("customer_id")!=profile.customer_id or project.get("visible") is False:
        raise ValueError("Expected internal customer/project not available")
    if not activity or activity.get("project_id") not in (None,profile.project_id) or activity.get("visible") is False:
        raise ValueError("Configured Kimai activity not available")
    return True




def reconcile_pending(client:KimaiClient,profile:Profile,user_id:int,
                      ledger:Ledger,days:list[dt.date]):
    """GET-only recovery of uncertain HTTP results.

    Resolve an intent ONLY when one exact remote timesheet matches its frozen
    payload. Zero or multiple matches remain uncertain, blocking future writes.
    This function never performs a network mutation and never retries a POST.
    """
    pending=ledger.pending()
    if not pending:
        return {"recovered":[],"unresolved":[]}
    rows=remote_day_list(client,user_id,days,profile.zone)
    recovered=[];unresolved=[]
    for item in pending:
        expected=item["payload"]["kimai"]
        matches=[r for r in rows if verified(r,expected,profile,user_id)
                 and (item["remote_id"] is None or item["remote_id"]==r.get("id"))]
        if len(matches)!=1:
            unresolved.append({"session_id":item["session_id"],
                               "action":item["action"],
                               "reason":"unmatched_or_ambiguous_remote_outcome"})
            continue
        ledger.recover_confirmed(item,matches[0])
        recovered.append({"session_id":item["session_id"],
                          "remote_id":matches[0]["id"],"action":item["action"]})
    return {"recovered":recovered,"unresolved":unresolved}

def execute(decision:Decision,*,client:KimaiClient,profile:Profile,user_id:int,ledger:Ledger):
    """Run one eligible, opted-in, nonbillable operation; never retry unknown."""
    if decision.action not in ("post","patch"):
        return {"status":"skipped","reason":decision.reason}
    if profile.sync_mode!="preview_nonbillable" or not profile.allow_auto_nonbillable_preview:
        raise ValueError("Explicit nonbillable preview opt-in missing")
    days=[dt.date.fromisoformat(decision.window.date)]
    remote=remote_day_list(client,user_id,days,profile.zone)
    current=plan_one(decision.window,profile,remote,ledger.get(decision.window.id),user_id=user_id)
    if (current.action!=decision.action or current.remote_id!=decision.remote_id):
        raise RuntimeError("Kimai changed since plan; operation blocked")
    proposed=payload(decision.window,profile)
    key=checksum({"account":user_id,"profile":profile.profile_id,
                  "session":decision.window.id,"action":decision.action,
                  "new_end":proposed["end"]})
    if ledger.intent(key) is not None:raise RuntimeError("Existing outbox intent; reconcile, do not retry")
    if decision.action=="patch":
        previous=next(r for r in remote if r["id"]==decision.remote_id)
        proposed["description"]=previous["description"]
    ledger.reserve(key,decision.window,decision.action,proposed)
    try:
        if decision.action=="post":
            remote_id=post_once(client,proposed)
        else:
            remote_id=decision.remote_id
            # Patch only the END field. Keep human-edited descriptions as-is.
            response=client.session.patch(
                client.base_url+"/api/timesheets/"+str(remote_id),
                json={"end":proposed["end"]},
                headers={"Authorization":"Bearer "+client.token,
                         "Content-Type":"application/json","Accept":"application/json",
                         "User-Agent":"automatic-kimai/cooperator-agent"},
                timeout=(3.0,18.0),allow_redirects=False)
            if response.status_code not in (200,204):
                raise RuntimeError("Non-success Kimai PATCH status")
    except (requests.RequestException,RuntimeError,ValueError) as exc:
        ledger.mark_unknown(key,remote_id=decision.remote_id)
        raise RuntimeError("Remote outcome unknown: GET reconcile required, NO retry") from None
    try:
        verified_rows=remote_day_list(client,user_id,days,profile.zone)
        row=next((r for r in verified_rows if r["id"]==remote_id),None)
        good=verified(row,proposed,profile,user_id)
    except (requests.RequestException,KimaiAPIError,TypeError,ValueError):
        good=False
    if not good:
        ledger.mark_unknown(key,remote_id=remote_id)
        raise RuntimeError("Remote GET mismatch, no blind retry")
    ledger.confirm(key,decision.window,row)
    return {"status":"reconciled","action":decision.action,"remote_id":remote_id}
