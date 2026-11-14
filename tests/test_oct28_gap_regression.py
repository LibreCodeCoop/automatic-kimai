"""28 October gap regression: do not lose observed work-context silently.

All fixtures are synthetic; no raw user data, tokens or chat content.
"""
from __future__ import annotations
import datetime as dt
import tempfile
import unittest
from pathlib import Path
from dataclasses import replace
from zoneinfo import ZoneInfo

from cooperator_agent.coverage import uncovered_activity
from cooperator_agent.profile import Profile
from cooperator_agent.segment import segment,classify
from cooperator_agent.reconcile import plan_one
from cooperator_agent.aw_source import url_kind

TZ=ZoneInfo("America/Sao_Paulo")
DAY="2026-10-28"

def when(clock,day=28):
    return dt.datetime.fromisoformat("2026-10-"+str(day)+"T"+clock+":00-03:00")

def event(a,b,kind="chat",url="",*,hint=None,afk=False):
    result={"start":a.isoformat(),"end":b.isoformat(),
            "url_kind":kind,"url":url,"afk":afk,
            "private":False,"idle_status_known":True}
    if hint is not None:result["messenger_hint"]={"client_hint":hint}
    return result

def profile(tmp,approved=False):
    x=Profile(profile_id="worker-one",timezone="America/Sao_Paulo",
              cache_dir=Path(tmp)/"cache",state_dir=Path(tmp)/"state",
              customer_id=2,project_id=41,activity_id=25,
              long_idle_minutes=5,max_session_hours=4,
              min_session_minutes=30,auto_min_work_minutes=7,
              auto_max_unknown_fraction=.56)
    if approved:
        x=replace(x,work_domains=("cloud.example.org","slides.com"),
                  work_messenger_labels=("Example Organization",))
    x.validate()
    return x

class GapRegressionTests(unittest.TestCase):
    def test_missing_independent_sources_explains_rejected_session(self):
        with tempfile.TemporaryDirectory() as root:
            p=profile(root)
            events=[
                event(when("18:06"),when("18:07"),"github"),
                event(when("18:07"),when("18:25"),"chat"),
                event(when("18:25"),when("18:31"),"internal_domain",
                      "https://cloud.example.org"),
                event(when("18:31"),when("18:41"),"none",hint="Example Organization"),
                event(when("18:41"),when("20:05"),"chat"),
            ]
            legacy=segment(DAY,events,[],p,when("23:00"))
            self.assertEqual(len(legacy),1)
            self.assertLess(legacy[0].work_seconds,7*60)
            approved=replace(p,work_domains=("cloud.example.org",),
                             work_messenger_labels=("Example Organization",))
            fixed=segment(DAY,events,[],approved,when("23:00"))
            self.assertEqual(len(fixed),1)
            self.assertGreater(fixed[0].work_seconds,7*60)
            self.assertEqual((fixed[0].start.strftime("%H:%M"),
                              fixed[0].end.strftime("%H:%M")),("18:06","20:05"))
            self.assertEqual(plan_one(fixed[0],approved,[],None,user_id=1).action,"post")

    def test_browser_work_site_requires_explicit_optin(self):
        url="https://slides.com/worker/presentation?secret=redact"
        self.assertEqual(url_kind(url),("https://slides.com","work_site"))
        with tempfile.TemporaryDirectory() as tmp:
            p=profile(tmp)
            e=event(when("16:00"),when("16:15"),"work_site","https://slides.com")
            self.assertEqual(classify(e,p),"other")
            self.assertEqual(classify(e,profile(tmp,approved=True)),"work")

    def test_gaps_report_work_without_claiming_afk_as_human(self):
        with tempfile.TemporaryDirectory() as tmp:
            p=profile(tmp,approved=True)
            events=[
                event(when("10:31"),when("14:24"),"github"),
                event(when("14:24"),when("15:25"),afk=True),
                event(when("15:25"),when("15:42"),"chat"),
                event(when("15:42"),when("17:52"),afk=True),
                event(when("17:52"),when("18:06"),"chat"),
                event(when("18:06"),when("18:16"),"none",hint="Example Organization"),
                event(when("18:16"),when("20:05"),"chat"),
                event(when("20:05"),when("21:30"),afk=True),
                event(when("21:30"),when("21:46"),"chat"),
                event(when("21:46"),when("23:57"),"github"),
            ]
            remote=[
                {"id":7,"begin":when("10:31").isoformat(),"end":when("14:24").isoformat()},
                {"id":8,"begin":when("21:46").isoformat(),"end":when("23:57").isoformat()},
            ]
            rows=uncovered_activity(DAY,events,remote,p,when("23:59"))
            gaps=[g for g in rows if g["start"].endswith("14:24:00-03:00")]
            self.assertEqual(len(gaps),1)
            self.assertEqual(gaps[0]["end"],when("21:46").isoformat())
            self.assertGreater(gaps[0]["observed_nonafk_minutes"],40)
            self.assertLess(gaps[0]["observed_nonafk_minutes"],
                            gaps[0]["elapsed_minutes"])
            self.assertTrue(gaps[0]["needs_human_review"])
            self.assertFalse(gaps[0]["human_duration_verified"])
            self.assertFalse(gaps[0]["billable"])
            filled=uncovered_activity(DAY,events,remote+[
                {"id":9,"begin":when("17:52").isoformat(),
                 "end":when("20:05").isoformat()}],p,when("23:59"))
            self.assertEqual(len(filled),2)
            self.assertFalse(any(g["start"]==when("17:52").isoformat() and
                                 g["end"]==when("20:05").isoformat() for g in filled))

    def test_midnight_endpoint_no_longer_loses_one_minute(self):
        with tempfile.TemporaryDirectory() as tmp:
            p=profile(tmp)
            events=[event(when("21:46"),
                          when("00:00",29)-dt.timedelta(microseconds=1),
                          "github")]
            windows=segment(DAY,events,[],p,when("12:00",29))
            self.assertEqual(len(windows),1)
            self.assertEqual(windows[0].end.strftime("%Y-%m-%d %H:%M"),
                             "2026-10-29 00:00")
            self.assertEqual(plan_one(windows[0],p,[],None,user_id=1).action,"post")

    def test_all_day_afk_produces_no_fake_work_gap(self):
        with tempfile.TemporaryDirectory() as tmp:
            p=profile(tmp,approved=True)
            events=[event(when("09:00"),when("17:00"),afk=True)]
            self.assertEqual(uncovered_activity(DAY,events,[],p,when("18:00")),[])

    def test_weekly_trial_migrates_without_losing_prior_cycles(self):
        import sqlite3
        from cooperator_agent.trial import record_cycle,weekly_summary
        with tempfile.TemporaryDirectory() as tmp:
            path=Path(tmp)/"trial.sqlite3"
            with sqlite3.connect(path) as conn:
                conn.execute("""CREATE TABLE cycles (
                    occurred_at TEXT PRIMARY KEY, result TEXT NOT NULL,
                    attempts INTEGER NOT NULL,writes INTEGER NOT NULL,
                    reviews INTEGER NOT NULL,lookback INTEGER NOT NULL,
                    truncated INTEGER NOT NULL,error_type TEXT)""")
                conn.execute(
                    "INSERT INTO cycles VALUES (?,?,?,?,?,?,?,?)",
                    (when("10:00").isoformat(),"success",1,1,0,3,0,None))
            path.chmod(0o600)
            first=weekly_summary(path,when("12:00"))
            self.assertEqual(first["writes"],1)
            self.assertEqual(first["coverage_gap_alerts"],0)
            record_cycle(path,{
                "at_local":when("12:30").isoformat(),
                "result":"success","attempted_publication":True,
                "remote_writes":0,"review_count":1,
                "lookback_days":3,"coverage_gap_count":2,
                "manual_review_interval_count":5,
                "calendar_gap_count":2,
            })
            report=weekly_summary(path,when("12:31"))
            self.assertEqual(report["cycles"],2)
            self.assertEqual(report["writes"],1)
            self.assertEqual(report["coverage_gap_alerts"],2)
            self.assertEqual(report["cycles_with_gaps"],1)
            self.assertEqual(report["latest_review_candidates"],5)
            self.assertEqual(report["latest_calendar_gaps"],2)
            self.assertEqual(report["cycles_with_calendar_gaps"],1)

if __name__=="__main__":
    unittest.main()
