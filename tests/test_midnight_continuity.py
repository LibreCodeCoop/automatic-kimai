"""Regression: the 23:59 cap must not lose a measured minute at midnight.

These fixtures are synthetic. Only the last minute of the previous day
is eligible for safe extension; weak ChatGPT evidence after midnight remains
review-only, without automatic work-hour assertions.
"""
from __future__ import annotations
import datetime as dt
import tempfile
import unittest
from pathlib import Path
from unittest.mock import Mock,patch
from zoneinfo import ZoneInfo

from cooperator_agent.profile import Profile
from cooperator_agent.segment import segment
from cooperator_agent.reconcile import (
    Ledger,description,execute,payload,plan_one,snapshot,valid_daily_interval)
from cooperator_agent.daily import collect_decisions

ZONE=ZoneInfo("America/Sao_Paulo")
DAY="2026-11-08"

def at(day,clock):
    return dt.datetime.fromisoformat(day+"T"+clock).replace(tzinfo=ZONE)

def event(start,end,kind="github"):
    return {"start":start.isoformat(),"end":end.isoformat(),
            "url_kind":kind,"afk":False,"private":False,
            "idle_status_known":True}

def profile(tmp):
    p=Profile(profile_id="worker-test",timezone="America/Sao_Paulo",
              cache_dir=Path(tmp)/"cache",state_dir=Path(tmp)/"state",
              customer_id=2,project_id=41,activity_id=25,
              sync_mode="preview_nonbillable",
              allow_auto_nonbillable_preview=True,
              lookback_days=2)
    p.validate()
    return p

def row(w,ident=990):
    return {"id":ident,"begin":w.start.isoformat(),"end":w.end.isoformat(),
            "duration":int((w.end-w.start).total_seconds()),
            "user":1,"project":41,"activity":25,
            "billable":False,"exported":False,
            "description":description(w)}

class MidnightContinuityTests(unittest.TestCase):
    def test_exactly_last_second_of_day_rounds_to_midnight(self):
        with tempfile.TemporaryDirectory() as tmp:
            p=profile(tmp)
            end=at("2026-11-09","00:00:00")-dt.timedelta(microseconds=1)
            w=segment(DAY,[event(at(DAY,"23:05:00"),end)],[],p,
                      at("2026-11-09","03:00:00"))[0]
            self.assertEqual(w.end,at("2026-11-09","00:00:00"))
            self.assertEqual((w.end-w.start).total_seconds(),55*60)
            self.assertEqual(payload(w,p)["end"],"2026-11-09T00:00:00")
            self.assertEqual(plan_one(w,p,[],None,user_id=1).action,"post")

    def test_no_extrapolation_if_desktop_stops_before_midnight(self):
        with tempfile.TemporaryDirectory() as tmp:
            p=profile(tmp)
            w=segment(DAY,[event(at(DAY,"23:05:00"),
                    at(DAY,"23:59:10"))],[],p,
                    at("2026-11-09","03:00:00"))[0]
            self.assertEqual(w.end,at(DAY,"23:59:00"))

    def test_prevent_general_cross_day_spans_and_negative_intervals(self):
        with tempfile.TemporaryDirectory() as tmp:
            p=profile(tmp)
            start=at(DAY,"23:05:00")
            self.assertTrue(valid_daily_interval(
                start,at("2026-11-09","00:00:00"),p.zone))
            for end in (at("2026-11-09","00:01:00"),
                        at("2026-11-09","03:00:00"),
                        at(DAY,"23:00:00")):
                self.assertFalse(valid_daily_interval(start,end,p.zone))

    def test_existing_2359_timesheet_patches_same_id_without_post(self):
        with tempfile.TemporaryDirectory() as tmp:
            p=profile(tmp)
            original=segment(DAY,[event(at(DAY,"23:05:00"),
                    at(DAY,"23:59:10"))],[],p,
                    at("2026-11-09","03:00:00"))[0]
            corrected=segment(DAY,[event(at(DAY,"23:05:00"),
                    at("2026-11-09","00:00:00")-
                    dt.timedelta(microseconds=1))],[],p,
                    at("2026-11-09","03:00:00"))[0]
            self.assertEqual(corrected.id,original.id)
            prior=row(original)
            after=row(corrected)
            ledger=Ledger(p.state_dir/"test-ledger.db",
                          user_id=1,profile_id=p.profile_id)
            try:
                ledger.db.execute(
                    "INSERT INTO sessions VALUES (?,?,?,?,?)",
                    (original.id,990,__import__("json").dumps(
                        snapshot(prior),sort_keys=True),
                     original.evidence_digest,"2026-11-09T00:10:00+00:00"))
                decision=plan_one(corrected,p,[prior],ledger.get(original.id),
                                  user_id=1)
                self.assertEqual((decision.action,decision.remote_id),
                                 ("patch",990))
                client=Mock()
                client.base_url="https://kimai.example"
                client.token="dummy"
                client.session.patch.return_value.status_code=200
                with patch("cooperator_agent.reconcile.remote_day_list",
                           side_effect=[[prior],[after]]),patch(
                           "cooperator_agent.reconcile.post_once") as post:
                    result=execute(decision,client=client,profile=p,
                                   user_id=1,ledger=ledger)
                self.assertEqual(result["remote_id"],990)
                post.assert_not_called()
                self.assertEqual(client.session.patch.call_args.kwargs["json"],
                                 {"end":"2026-11-09T00:00:00"})
                self.assertEqual(plan_one(corrected,p,[after],
                                 ledger.get(corrected.id),user_id=1).action,
                                 "unchanged")
                self.assertEqual(len(list(ledger.db.execute(
                    "SELECT * FROM sessions"))),1)
            finally:
                ledger.close()

    def test_review_report_explicitly_marks_possible_overnight_continuation(self):
        from cooperator_agent.daily import annotate_overnight_reviews
        prev={"day":DAY,"sessions":[{
            "begin":"2026-11-08T23:05:00-03:00",
            "end":"2026-11-09T00:00:00-03:00",
            "action":"unchanged"}]}
        following={"day":"2026-11-09","sessions":[{
            "begin":"2026-11-09T00:00:00-03:00",
            "end":"2026-11-09T01:01:00-03:00",
            "action":"review",
            "reason":"insufficient_independent_desktop_evidence"}]}
        days=[prev,following]
        self.assertEqual(annotate_overnight_reviews(days),1)
        self.assertTrue(following["sessions"][0]["possible_overnight_continuation"])
        self.assertEqual(following["sessions"][0]["action"],"review")
        self.assertEqual(following["sessions"][0]["reason"],
                         "insufficient_independent_desktop_evidence")

    def test_chat_only_after_midnight_remains_review_not_auto_work(self):
        with tempfile.TemporaryDirectory() as tmp:
            p=profile(tmp)
            earlier=[event(at(DAY,"23:05:00"),
                           at("2026-11-09","00:00:00")-
                           dt.timedelta(microseconds=1))]
            later=[event(at("2026-11-09","00:00:00"),
                         at("2026-11-09","01:01:00"),kind="chat")]
            with patch("cooperator_agent.daily._get_events",
                       side_effect=lambda prof,date,now:
                           (earlier if date.isoformat()==DAY else later,None)):
                ledger=Ledger(p.state_dir/"test-ledger.db",user_id=1,
                              profile_id=p.profile_id)
                try:
                    result=collect_decisions(
                        p,now=at("2026-11-09","03:00:00"),remote=[],
                        ledger=ledger,user_id=1)
                finally:
                    ledger.close()
            first=next(r for r in result if r["day"]==DAY)
            second=next(r for r in result if r["day"]=="2026-11-09")
            self.assertEqual(first["sessions"][0][0].action,"post")
            self.assertEqual(second["sessions"][0][0].action,"review")
            self.assertEqual(second["sessions"][0][0].reason,
                             "insufficient_independent_desktop_evidence")

if __name__=="__main__":
    unittest.main()
