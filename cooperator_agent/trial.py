"""Seven-day pilot diagnostics: one local row per 30-minute cycle.

No tokens, chat text, browser URLs, customer names, or human-hour
certifications. This does not change Kimai or create follow-up automation.
"""
from __future__ import annotations
import datetime as dt
import json
import os
import sqlite3
from pathlib import Path


def record_cycle(path:Path,status:dict):
    p=Path(path)
    p.parent.mkdir(parents=True,exist_ok=True,mode=0o700)
    if p.is_symlink():raise ValueError("Symlinked trial database")
    if not p.exists():
        fd=os.open(p,os.O_CREAT|os.O_EXCL|os.O_WRONLY,0o600)
        os.close(fd)
    if os.name!="nt" and p.stat().st_mode&0o077:
        raise ValueError("Insecure trial database")
    with sqlite3.connect(p,timeout=10) as db:
        db.execute("""CREATE TABLE IF NOT EXISTS cycles (
            occurred_at TEXT PRIMARY KEY,
            result TEXT NOT NULL,
            attempts INTEGER NOT NULL,
            writes INTEGER NOT NULL,
            reviews INTEGER NOT NULL,
            lookback INTEGER NOT NULL,
            truncated INTEGER NOT NULL,
            error_type TEXT)""")
        columns={r[1] for r in db.execute("PRAGMA table_info(cycles)")}
        if "coverage_gaps" not in columns:
            db.execute("ALTER TABLE cycles ADD COLUMN coverage_gaps INTEGER NOT NULL DEFAULT 0")
        if "review_candidates" not in columns:
            db.execute("ALTER TABLE cycles ADD COLUMN review_candidates INTEGER NOT NULL DEFAULT 0")
        if "calendar_gaps" not in columns:
            db.execute("ALTER TABLE cycles ADD COLUMN calendar_gaps INTEGER NOT NULL DEFAULT 0")
        instant=status["at_local"]
        db.execute("""INSERT OR REPLACE INTO cycles
                    (occurred_at,result,attempts,writes,reviews,lookback,truncated,
                     error_type,coverage_gaps,review_candidates,calendar_gaps) VALUES (?,?,?,?,?,?,?,?,?,?,?)""",
                   (instant,status["result"],
                    int(bool(status.get("attempted_publication"))),
                    int(status.get("remote_writes",0)),
                    int(status.get("review_count",0)),
                    int(status.get("lookback_days",0)),
                    int(bool(status.get("older_backlog_needs_manual_review"))),
                    status.get("error_type"),
                    int(status.get("coverage_gap_count",0)),
                    int(status.get("manual_review_interval_count",0)),
                    int(status.get("calendar_gap_count",0))))
        # Retain at most 35 days for later diagnosis, with bounded disk usage.
        cutoff=(dt.datetime.fromisoformat(instant)-dt.timedelta(days=35)).isoformat()
        db.execute("DELETE FROM cycles WHERE occurred_at < ?",(cutoff,))


def weekly_summary(path:Path,now:dt.datetime):
    p=Path(path)
    if not p.is_file():
        return {"state":"not_started","days":0,"cycles":0,"writes":0,"reviews":0}
    with sqlite3.connect("file:"+str(p)+"?mode=ro",uri=True) as db:
        has_gaps=any(x[1]=="coverage_gaps" for x in db.execute("PRAGMA table_info(cycles)"))
        column="coverage_gaps" if has_gaps else "0 AS coverage_gaps"
        has_review=any(x[1]=="review_candidates"
                       for x in db.execute("PRAGMA table_info(cycles)"))
        review_column=("review_candidates" if has_review else "0 AS review_candidates")
        has_calendar=any(x[1]=="calendar_gaps"
                         for x in db.execute("PRAGMA table_info(cycles)"))
        calendar_column=("calendar_gaps" if has_calendar else "0 AS calendar_gaps")
        rows=db.execute(
            "SELECT occurred_at,result,attempts,writes,reviews,truncated,"+column+
            ","+review_column+","+calendar_column+
            " FROM cycles ORDER BY occurred_at").fetchall()
    if not rows:return {"state":"not_started","days":0,"cycles":0,"writes":0,"reviews":0}
    start=dt.datetime.fromisoformat(rows[0][0])
    elapsed=(now.astimezone(start.tzinfo)-start).total_seconds()/86400
    week=[r for r in rows if dt.datetime.fromisoformat(r[0])<=start+dt.timedelta(days=7)]
    counts={}
    for r in week:counts[r[1]]=counts.get(r[1],0)+1
    return {
        "state":"review_due" if elapsed>=7 else "pilot_running",
        "started":start.isoformat(),
        "review_from":(start+dt.timedelta(days=7)).isoformat(),
        "days":round(min(max(elapsed,0),7),2),
        "cycles":len(week),
        "writes":sum(r[3] for r in week),
        "reviews":sum(r[4] for r in week),
        "coverage_gap_alerts":sum(r[6] for r in week),
        "cycles_with_gaps":sum(r[6]>0 for r in week),
        # "latest" must refer to the current cycle, even after the original
        # seven-day validation window has ended.
        "latest_review_candidates":rows[-1][7],
        "cycles_with_review_candidates":sum(r[7]>0 for r in week),
        "latest_calendar_gaps":rows[-1][8],
        "cycles_with_calendar_gaps":sum(r[8]>0 for r in week),
        "failed_cycles":sum(r[1] not in ("success","capture_only") for r in week),
        "outcomes":counts,
        "catchup_over_7_days_count":sum(r[5] for r in week),
        "financially_approved":False,
    }
