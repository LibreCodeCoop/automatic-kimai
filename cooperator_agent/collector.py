"""Portable collection of local ActivityWatch evidence into per-worker cache.

ActivityWatch is a local user-installed dependency, not a remote service.
A collector failure never rewrites an existing snapshot with empty data.
No raw window titles, messages or non-allowlisted URLs are stored here.
"""
from __future__ import annotations
import datetime as dt
import json
import os
from pathlib import Path
from zoneinfo import ZoneInfo

from .aw_source import AWReader,ActivityWatchError,collect
from kimai_client import save_private_json
from .profile import Profile


class CollectionUnavailable(RuntimeError):
    pass


def collect_days(profile:Profile, *, now:dt.datetime|None=None,
                 reader:AWReader|None=None, days:int|None=None) -> dict:
    profile.validate()
    clock=(now or dt.datetime.now(profile.zone)).astimezone(profile.zone)
    lookback=days or profile.lookback_days
    if type(lookback) is not int or not 1 <= lookback <= 7:
        raise ValueError("Invalid collection lookback")
    # The public package has one self-contained ActivityWatch implementation.
    # The historical pilot's separate importer is not distributed here.
    source=reader or AWReader()
    captured={}
    profile.cache_dir.mkdir(parents=True,exist_ok=True,mode=0o700)
    if profile.cache_dir.is_symlink():
        raise CollectionUnavailable("Refusing symlinked collection cache")
    # Only our sanitized cache is persisted; ActivityWatch remains local.
    for offset in reversed(range(lookback)):
        day=clock.date()-dt.timedelta(days=offset)
        low=dt.datetime.combine(day,dt.time.min,tzinfo=profile.zone)
        high=min(clock,low+dt.timedelta(days=1))
        if low>=high:continue
        target=profile.cache_dir/f"aw_sanitized_{day.isoformat()}.json"
        if target.is_symlink():
            raise CollectionUnavailable("Refusing symlinked day cache")
        try:
            args=(source,low.astimezone(dt.timezone.utc),
                  high.astimezone(dt.timezone.utc))
            events,stats=collect(*args,approved_domains=profile.work_domains)
        except (ActivityWatchError,ConnectionError,TimeoutError,ValueError) as exc:
            # Do not swallow mid-run failures: next OS scheduled execution
            # will retry from ActivityWatch's persisted database.
            raise CollectionUnavailable(
                "ActivityWatch unavailable: historical evidence retained, no Kimai write"
            ) from None
        if stats.get("afk_events",0)<1 or stats.get("window_events",0)<1:
            # A live but empty/half-running watcher is NOT proof of absence
            # and must never erase a prior valid snapshot.
            captured[day.isoformat()]={"status":"insufficient_source",
                                       "events":len(events)}
            continue
        if events and not all(e.get("idle_status_known") in (True,False)
                              for e in events):
            raise CollectionUnavailable("ActivityWatch event missing AFK evidence field")
        save_private_json(target,{
            "local_date":day.isoformat(),
            "fetched_at":dt.datetime.now(dt.timezone.utc).isoformat(),
            "source":"ActivityWatch localhost (window + AFK + browser tabs)",
            "privacy":"only sanitized events; no private full URLs or raw window titles",
            "stats":stats,
            "events":events
        })
        captured[day.isoformat()]={"status":"stored","events":len(events),
                                   "afk_events":stats["afk_events"]}
    return captured
