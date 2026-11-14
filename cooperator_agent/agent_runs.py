"""Optional, privacy-preserving execution telemetry for AI/remote tools.

A process being alive is not proof of a person's labor. Store only source,
timestamps, opaque run IDs and completion state, NEVER prompts, command lines,
secrets, titles or GitHub issue content.

This feed is NOT automatically populated from ChatGPT. A verified integration
must emit start/finish events for each individual agent task. Missing events
must never be interpreted as an idle/finished agent.
"""
from __future__ import annotations
import argparse
import datetime as dt
import hashlib
import json
import os
import re
import sqlite3
import uuid
from pathlib import Path

from .profile import Profile

SOURCES=("remote_desktop_mcp","chatgpt_work","codex_cli","other")
TERMINAL=("completed","failed","cancelled")


def _time(value=None):
    x=value or dt.datetime.now(dt.timezone.utc)
    if x.tzinfo is None:raise ValueError("Agent task time must include UTC offset")
    return x.astimezone(dt.timezone.utc).isoformat()


class AgentRuns:
    """Account/profile scoped audit. SQLite transactions are durable and local."""
    def __init__(self,path:Path,profile_id:str,*,create=True):
        self.path=Path(path)
        self.profile_id=profile_id
        if self.path.is_symlink() or self.path.parent.is_symlink():
            raise ValueError("Symlinked agent telemetry forbidden")
        if not self.path.exists() and not create:
            self.db=None
            return
        self.path.parent.mkdir(parents=True,exist_ok=True,mode=0o700)
        if os.name!="nt" and self.path.parent.stat().st_mode&0o077:
            raise ValueError("Agent telemetry directory must be private")
        if not self.path.exists():
            fd=os.open(self.path,os.O_CREAT|os.O_EXCL|os.O_WRONLY,0o600)
            os.close(fd)
        if os.name!="nt" and self.path.stat().st_mode&0o077:
            raise ValueError("Agent telemetry file must be private")
        self.db=sqlite3.connect(self.path,timeout=10,isolation_level=None)
        self.db.execute("PRAGMA busy_timeout=10000")
        self.db.execute("PRAGMA synchronous=FULL")
        self.db.execute("CREATE TABLE IF NOT EXISTS metadata (key TEXT PRIMARY KEY,value TEXT NOT NULL)")
        self.db.execute("""CREATE TABLE IF NOT EXISTS runs (
            run_id TEXT PRIMARY KEY,source TEXT NOT NULL,start_utc TEXT NOT NULL,
            end_utc TEXT,state TEXT NOT NULL CHECK(state IN
            ('running','completed','failed','cancelled')))""")
        ident=hashlib.sha256(profile_id.encode()).hexdigest()
        previous=self.db.execute("SELECT value FROM metadata WHERE key='worker'").fetchone()
        if previous and previous[0]!=ident:
            self.close()
            raise ValueError("Agent audit belongs to a different worker")
        self.db.execute("INSERT OR IGNORE INTO metadata VALUES ('worker',?)",(ident,))

    def close(self):
        if self.db is not None:self.db.close()
        self.db=None

    def start(self,source:str,run_id:str=None,at=None):
        if self.db is None:raise ValueError("Event store is not initialized")
        if source not in SOURCES:raise ValueError("Unsupported task source")
        run_id=run_id or uuid.uuid4().hex
        if not re.fullmatch(r"[A-Za-z0-9_-]{12,70}",run_id):
            raise ValueError("Run ID must be an opaque identifier, no descriptive text")
        instant=_time(at)
        self.db.execute("INSERT INTO runs VALUES (?,?,?,NULL,'running')",
                        (run_id,source,instant))
        return run_id

    def finish(self,run_id:str,status="completed",at=None):
        if self.db is None:raise ValueError("Event store is not initialized")
        if status not in TERMINAL:raise ValueError("Unsupported task completion status")
        instant=_time(at)
        row=self.db.execute("SELECT start_utc,state FROM runs WHERE run_id=?",
                            (run_id,)).fetchone()
        if not row or row[1]!="running":
            raise ValueError("Unknown or already completed AI task")
        if dt.datetime.fromisoformat(row[0])>dt.datetime.fromisoformat(instant):
            raise ValueError("Agent task cannot finish before it begins")
        self.db.execute("UPDATE runs SET end_utc=?,state=? WHERE run_id=? AND state='running'",
                        (instant,status,run_id))
        if self.db.execute("SELECT changes()").fetchone()[0]!=1:
            raise RuntimeError("Run was changed concurrently")
        return status

    def intervals(self,since,until):
        if since.tzinfo is None or until.tzinfo is None or since>=until:
            raise ValueError("Invalid query range")
        if self.db is None:return []
        now=dt.datetime.now(dt.timezone.utc)
        cutoff=min(until,now)
        result=[]
        for run_id,source,start,end,status in self.db.execute(
            "SELECT run_id,source,start_utc,end_utc,state FROM runs"):
            a=dt.datetime.fromisoformat(start)
            b=dt.datetime.fromisoformat(end) if end else cutoff
            left,right=max(since,a),min(until,b)
            if left>=right:continue
            result.append({"run_id":run_id,"source":source,
                           "start":left,"end":right,"state":status,
                           "execution_seconds":int((right-left).total_seconds()),
                           "counts_as_human_work":False,
                           "attribution":"agent_runtime_only"})
        return sorted(result,key=lambda e:(e["start"],e["run_id"]))


def overlaps(window,runs):
    """Measure UNION of concurrent agent work, never sum parallel tasks."""
    spans=sorted((max(window.start,r["start"]),min(window.end,r["end"]))
                 for r in runs if r["start"]<window.end and window.start<r["end"])
    merged=[]
    for a,b in spans:
        if not merged or a>merged[-1][1]:
            merged.append((a,b))
        elif b>merged[-1][1]:
            merged[-1]=(merged[-1][0],b)
    return sum(int((b-a).total_seconds()) for a,b in merged)


def main():
    cli=argparse.ArgumentParser(description="Private AI-task execution markers (not Kimai writes)")
    cli.add_argument("--profile",type=Path,required=True)
    modes=cli.add_mutually_exclusive_group(required=True)
    modes.add_argument("--start",choices=SOURCES)
    modes.add_argument("--finish",choices=TERMINAL)
    cli.add_argument("--run-id",help="Opaque run identifier from start, not a prompt or filename")
    args=cli.parse_args()
    profile=Profile.read(args.profile)
    path=profile.state_dir/"agent_runs.sqlite3"
    store=AgentRuns(path,profile.profile_id)
    try:
        if args.start:print("AGENT_RUN_STARTED",store.start(args.start,args.run_id))
        else:
            if not args.run_id:cli.error("--finish needs --run-id")
            print("AGENT_RUN_FINISHED",args.run_id,store.finish(args.run_id,args.finish))
    finally:store.close()


if __name__=="__main__":
    main()
