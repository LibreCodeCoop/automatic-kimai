"""Offline events and conservative recovery of human-interaction windows.

No live endpoint, user-specific IDs or token in test fixtures.
"""
from __future__ import annotations
import datetime as dt
import tempfile
import unittest
from dataclasses import replace
from pathlib import Path
from unittest.mock import patch

from zoneinfo import ZoneInfo
from kimai_client import save_private_json
from cooperator_agent.attendance import (AttendancePlan,apply_plan,
                                         planned_segments,read_plan_records,record_plan)
from cooperator_agent.profile import Profile


Z=ZoneInfo("America/Sao_Paulo")
NOW=dt.datetime(2026,10,31,15,0,tzinfo=Z)

def profile(tmp):
    p=Profile(profile_id="worker-test",timezone="America/Sao_Paulo",
              cache_dir=Path(tmp)/"cache",state_dir=Path(tmp)/"state",
              customer_id=2,project_id=41,activity_id=25,
              max_session_hours=4)
    p.validate()
    path=Path(tmp)/"profile.json"
    save_private_json(path,{
        **{k:getattr(p,k) for k in Profile.__dataclass_fields__
           if k not in ("cache_dir","state_dir","work_topic_hints",
                        "work_domains","work_messenger_labels")},
        "cache_dir":str(p.cache_dir),"state_dir":str(p.state_dir),
        "work_topic_hints":[],"work_domains":[],"work_messenger_labels":[],
    })
    return p,path


class OfflineAttendanceTests(unittest.TestCase):
    def test_program_plus_attendance_yields_split_not_full_day(self):
        with tempfile.TemporaryDirectory() as root:
            p,_=profile(root)
            x=AttendancePlan("2026-10-30","Encontro presencial na cooperativa",
                             "10:00","17:00",(("12:30","13:30"),),
                             "previous_workshop_agenda",True)
            self.assertEqual(planned_segments(x,p,NOW),[
                ("10:00","12:30"),("13:30","17:00")])
            self.assertEqual(sum((int(b[:2])*60+int(b[3:])-
                                 (int(a[:2])*60+int(a[3:]))) for a,b in planned_segments(x,p,NOW)),360)
            ident,spans=record_plan(p,x,now=NOW)
            self.assertEqual(ident,record_plan(p,x,now=NOW)[0])
            rows=read_plan_records(p,["2026-10-30"])
            self.assertEqual(len(rows),1)
            self.assertTrue(rows[0]["attendance_attested"])
            self.assertTrue(rows[0]["bounds_estimated"])
            self.assertFalse(rows[0]["payroll_approved"])
            self.assertFalse(rows[0]["billable"])

    def test_calendar_entry_alone_is_not_attendance(self):
        with tempfile.TemporaryDirectory() as root:
            p,_=profile(root)
            item=AttendancePlan("2026-10-30","Reunião presencial",
                                "10:00","17:00",(("12:30","13:30"),),
                                "calendar",False)
            with self.assertRaisesRegex(ValueError,"not attendance"):
                planned_segments(item,p,NOW)

    def test_unsafe_unconfirmed_breaks_or_long_segments_fail(self):
        with tempfile.TemporaryDirectory() as root:
            p,_=profile(root)
            base=AttendancePlan("2026-10-30","Reunião presencial",
                                "10:00","17:00",(("12:30","13:30"),),
                                "worker_attested",True)
            with self.assertRaisesRegex(ValueError,"Split"):
                planned_segments(replace(base,breaks=()),p,NOW)
            with self.assertRaisesRegex(ValueError,"Break"):
                planned_segments(replace(base,breaks=(("09:00","10:00"),)),p,NOW)
            with self.assertRaisesRegex(ValueError,"Invalid"):
                planned_segments(replace(base,start="17:00",end="10:00"),p,NOW)
            with self.assertRaisesRegex(ValueError,"Source"):
                planned_segments(replace(base,schedule_source=""),p,NOW)
            with self.assertRaisesRegex(ValueError,"attendance"):
                apply_plan(profile_path=Path(root)/"profile.json",
                           env_path=Path(root)/".env",plan=base,
                           attendance_confirmed=False,now=NOW)

    def test_attendance_publishes_only_after_explicit_consent_with_provenance(self):
        with tempfile.TemporaryDirectory() as root:
            p,path=profile(root)
            plan=AttendancePlan("2026-10-30","Encontro presencial em instituição parceira",
                                "10:00","17:00",(("12:30","13:30"),),
                                "prior_agenda_user_report",True)
            calls=[]
            def fake_submit(**kwargs):
                calls.append(kwargs)
                return {"status":"reconciled","remote_id":100+len(calls),
                        "minutes":150 if len(calls)==1 else 210,
                        "basis":kwargs["basis"],"payroll_approved":False}
            with patch("cooperator_agent.attendance.submit",side_effect=fake_submit):
                x=apply_plan(profile_path=path,env_path=Path(root)/".env",
                             plan=plan,attendance_confirmed=True,now=NOW)
            self.assertEqual(len(calls),2)
            self.assertEqual(x["day"],"2026-10-30")
            self.assertTrue(x["bounds_estimated"])
            self.assertEqual([c["start_hm"] for c in calls],["10:00","13:30"])
            self.assertTrue(all(c["basis"]=="reported_attendance_scheduled_bounds"
                                and c["confirmed"] is True for c in calls))
            self.assertFalse(x["payroll_approved"])


if __name__=="__main__":
    unittest.main()
