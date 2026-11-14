"""Calendar schedule gaps must be review-only, including off-computer work."""
from __future__ import annotations
import datetime as dt
import json
import tempfile
import unittest
from pathlib import Path
from zoneinfo import ZoneInfo

from kimai_client import save_private_json
from cooperator_agent.profile import Profile
from cooperator_agent.coverage import scheduled_event_gaps
from cooperator_agent.daily import collect_decisions,render_private_html

Z=ZoneInfo("America/Sao_Paulo")
NOW=dt.datetime(2026,11,7,15,0,tzinfo=Z)
DAY="2026-11-06"

def profile(root):
    p=Profile(profile_id="worker-test",timezone="America/Sao_Paulo",
              cache_dir=Path(root)/"cache",state_dir=Path(root)/"state",
              customer_id=2,project_id=41,activity_id=25,
              lookback_days=2,alert_on_unrecorded_weekdays=True)
    p.validate()
    return p

def event(start="15:00",end="17:00",**props):
    return {"date":DAY,"start_local":f"{DAY}T{start}:00-03:00",
            "end_local":f"{DAY}T{end}:00-03:00",
            "all_day":False,"category":"unclassified_not_work_proof",
            "scheduled_only":True,"private_summary":"Never persist meeting notes",
            **props}

def record(start,end,day=DAY):
    return {"begin":f"{day}T{start}:00-03:00",
            "end":f"{day}T{end}:00-03:00"}

class CalendarGapReviewTests(unittest.TestCase):
    def test_scheduled_meeting_detected_despite_unrelated_kimai_entry(self):
        with tempfile.TemporaryDirectory() as root:
            p=profile(root)
            entries=[record("12:00","13:38")]
            gaps=scheduled_event_gaps(DAY,[event()],entries,p,NOW)
            self.assertEqual(len(gaps),1)
            self.assertEqual(gaps[0]["uncovered_schedule_minutes"],120)
            self.assertEqual(gaps[0]["recorded_overlap_minutes"],0)
            self.assertFalse(gaps[0]["attendance_verified"])
            self.assertEqual(gaps[0]["hours_inferred"],0)
            self.assertFalse(gaps[0]["billable"])
            self.assertFalse(gaps[0]["payroll_approved"])
            self.assertNotIn("private_summary",gaps[0])

    def test_recorded_meeting_entry_resolves_gap_without_double_counting(self):
        with tempfile.TemporaryDirectory() as root:
            p=profile(root)
            entries=[record("12:00","13:38"),record("15:00","17:00")]
            self.assertEqual(scheduled_event_gaps(DAY,[event()],entries,p,NOW),[])
            # Even repeated, shared calendars do not multiply review items.
            self.assertEqual(len(scheduled_event_gaps(DAY,[event(),event()],entries[:1],p,NOW)),1)

    def test_partial_coverage_subtracts_overlapping_timesheets_once(self):
        with tempfile.TemporaryDirectory() as root:
            p=profile(root)
            entries=[record("14:30","15:45"),record("15:30","16:00"),
                     record("16:30","16:45")]
            gaps=scheduled_event_gaps(DAY,[event()],entries,p,NOW)
            self.assertEqual(len(gaps),1)
            self.assertEqual(gaps[0]["uncovered_schedule_minutes"],45)
            self.assertEqual(gaps[0]["recorded_overlap_minutes"],75)
            self.assertEqual([(i["start"][11:16],i["end"][11:16])
                              for i in gaps[0]["uncovered_intervals"]],
                             [("16:00","16:30"),("16:45","17:00")])

    def test_personal_declined_all_day_and_future_never_create_alerts(self):
        with tempfile.TemporaryDirectory() as root:
            p=profile(root)
            blocked=[event(category="personal_excluded"),
                     event(category="calendar_declined_excluded"),
                     event(all_day=True),
                     event(start="15:00",end="15:10"),
                     event(category="invalid_category"),
                     event(scheduled_only=False),
                     event(start="15:00",end="17:00",
                           end_local="2026-11-08T17:00:00-03:00")]
            self.assertEqual(scheduled_event_gaps(DAY,blocked,[],p,NOW),[])
            today="2026-11-07"
            running=[{"date":today,"start_local":today+"T14:00:00-03:00",
                      "end_local":today+"T16:00:00-03:00","all_day":False,
                      "category":"possible_meeting_unclassified",
                      "scheduled_only":True}]
            self.assertEqual(scheduled_event_gaps(today,running,[],p,NOW),[])

    def test_cross_midnight_portable_event_without_matching_date_still_flags(self):
        with tempfile.TemporaryDirectory() as root:
            p=profile(root)
            ev={"date":"2026-11-05","start_local":"2026-11-05T23:30:00-03:00",
                "end_local":"2026-11-06T01:00:00-03:00",
                "all_day":False,"scheduled_only":True,
                "category":"possible_meeting_unclassified"}
            gaps=scheduled_event_gaps(DAY,[ev],[],p,NOW)
            self.assertEqual(len(gaps),1)
            self.assertEqual(gaps[0]["start"][11:16],"00:00")
            self.assertEqual(gaps[0]["uncovered_schedule_minutes"],60)

    def test_alert_even_with_missing_activitywatch_file_and_no_auto_post(self):
        with tempfile.TemporaryDirectory() as root:
            p=profile(root)
            save_private_json(p.cache_dir/"calendar_work_context.json",
                              {"generated_utc":NOW.isoformat(),"events":[event()]})
            entries=[record("12:00","13:38")]
            days=collect_decisions(p,now=NOW,remote=entries,
                                   ledger=None,user_id=1,calendar_enabled=True)
            # A portable worker that disables Calendar must not reuse an
            # earlier calendar cache for warnings or work inference.
            opted_out=collect_decisions(p,now=NOW,remote=entries,
                                        ledger=None,user_id=1,calendar_enabled=False)
            self.assertFalse(any(x["calendar_gap_alerts"] for x in opted_out))
            friday=next(row for row in days if row["day"]==DAY)
            self.assertEqual(friday["warning"],"missing_activitywatch_file")
            self.assertEqual(friday["sessions"],[])
            self.assertEqual(friday["missing_workday_alerts"],[])
            self.assertEqual(len(friday["calendar_gap_alerts"]),1)
            p.state_dir.mkdir()
            render_private_html({"days":[{
                "day":DAY,"warning":friday["warning"],"sessions":[],
                "coverage_gaps":[],"review_intervals":[],
                "missing_workday_alerts":[],
                "calendar_gap_alerts":friday["calendar_gap_alerts"]}],
                "profile_id":p.profile_id,"created_at":NOW.isoformat(),
                "offscreen_attendance":[]},p.state_dir)
            html=(p.state_dir/"latest_daily_plan.html").read_text()
            self.assertIn("Compromissos agendados sem cobertura",html)
            self.assertIn("15:00",html)
            self.assertNotIn("Never persist meeting notes",html)

    def test_trial_latest_metric_uses_current_cycle_after_seven_days(self):
        from cooperator_agent.trial import record_cycle,weekly_summary
        with tempfile.TemporaryDirectory() as root:
            db=Path(root)/"trial.sqlite3"
            record_cycle(db,{"at_local":NOW.isoformat(),"result":"success",
                             "lookback_days":3,"calendar_gap_count":0,
                             "manual_review_interval_count":0})
            later=NOW+dt.timedelta(days=8)
            record_cycle(db,{"at_local":later.isoformat(),"result":"success",
                             "lookback_days":3,"calendar_gap_count":2,
                             "manual_review_interval_count":3})
            summary=weekly_summary(db,later)
            self.assertEqual(summary["state"],"review_due")
            self.assertEqual(summary["latest_calendar_gaps"],2)
            self.assertEqual(summary["latest_review_candidates"],3)


if __name__=="__main__":
    unittest.main()
