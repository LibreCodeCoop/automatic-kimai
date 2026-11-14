"""Do not silently lose off-computer days or infer work from calendars."""
from __future__ import annotations
import datetime as dt
import tempfile
import unittest
from dataclasses import replace
from pathlib import Path
from zoneinfo import ZoneInfo
from cooperator_agent.profile import Profile
from cooperator_agent.coverage import missing_expected_workday
from cooperator_agent.daily import collect_decisions

Z=ZoneInfo("America/Sao_Paulo")
NOW=dt.datetime(2026,10,31,15,0,tzinfo=Z)

def profile(root):
    p=Profile(profile_id="cooperator-a",timezone="America/Sao_Paulo",
              cache_dir=Path(root)/"cache",state_dir=Path(root)/"state",
              customer_id=2,project_id=41,activity_id=25,
              alert_on_unrecorded_weekdays=True,lookback_days=2)
    p.validate()
    return p

class OffscreenGapAlertsTests(unittest.TestCase):
    def test_completed_workday_without_desktop_or_calendar_still_warns(self):
        with tempfile.TemporaryDirectory() as root:
            p=profile(root)
            warnings=missing_expected_workday(p,"2026-10-30",[],NOW)
            self.assertEqual(len(warnings),1)
            self.assertEqual(warnings[0]["hours_inferred"],0)
            self.assertFalse(warnings[0]["payroll_approved"])
            self.assertEqual(warnings[0]["reason"],
                             "completed_weekday_without_kimai_timesheets")
            rows=collect_decisions(p,now=NOW,remote=[],ledger=None,user_id=1)
            friday=next(r for r in rows if r["day"]=="2026-10-30")
            self.assertEqual(friday["warning"],"missing_activitywatch_file")
            self.assertEqual(len(friday["missing_workday_alerts"]),1)
            self.assertEqual(friday["sessions"],[])

    def test_recorded_day_has_no_offscreen_warning(self):
        with tempfile.TemporaryDirectory() as root:
            p=profile(root)
            recorded=[{"begin":"2026-10-30T10:00:00-03:00",
                       "end":"2026-10-30T12:30:00-03:00"}]
            self.assertEqual(
                missing_expected_workday(p,"2026-10-30",recorded,NOW),[])

    def test_optout_weekends_and_current_days_do_not_guess(self):
        with tempfile.TemporaryDirectory() as root:
            p=profile(root)
            self.assertEqual(
                missing_expected_workday(p,"2026-10-31",[],NOW),[])
            self.assertEqual(
                missing_expected_workday(p,"2026-10-29",[],
                                          dt.datetime(2026,10,29,15,tzinfo=Z)),[])
            self.assertEqual(
                missing_expected_workday(replace(p,alert_on_unrecorded_weekdays=False),
                                         "2026-10-30",[],NOW),[])

if __name__=="__main__":
    unittest.main()
