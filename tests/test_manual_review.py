"""Explicit human confirmation and review candidates (no live external API)."""
from __future__ import annotations
import datetime as dt
import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch
from zoneinfo import ZoneInfo

from kimai_client import save_private_json
from cooperator_agent.manual_review import (self_report_window,bounds,submit)
from cooperator_agent.reviewable import reviewable_intervals
from cooperator_agent.profile import Profile

TZ=ZoneInfo("America/Sao_Paulo")
NOW=dt.datetime(2026,10,29,17,50,tzinfo=TZ)

def profile(temp):
    p=Profile(profile_id="unit-worker",timezone="America/Sao_Paulo",
              cache_dir=Path(temp)/"cache",state_dir=Path(temp)/"state",
              customer_id=2,project_id=41,activity_id=25,
              max_session_hours=4,long_idle_minutes=5,
              max_bridge_minutes=20,lookback_days=3)
    p.validate()
    path=Path(temp)/"config"/"profile.json"
    save_private_json(path,{
        **{k:getattr(p,k) for k in Profile.__dataclass_fields__
           if k not in ("cache_dir","state_dir","work_topic_hints",
                         "work_domains","work_messenger_labels")},
        "cache_dir":str(p.cache_dir),"state_dir":str(p.state_dir),
        "work_topic_hints":[],"work_domains":[],"work_messenger_labels":[]
    })
    return p,path

def moment(clock,day=29):
    return dt.datetime.fromisoformat(f"2026-10-{day}T{clock}:00-03:00")

def aw(a,b,k="chat",afk=False):
    return {"start":a.isoformat(),"end":b.isoformat(),
            "url_kind":k,"afk":afk,"private":False,
            "idle_status_known":True}

class ManualReviewTests(unittest.TestCase):
    def test_review_spans_are_split_at_long_afk_and_existing_timesheets(self):
        with tempfile.TemporaryDirectory() as temp:
            p,path=profile(temp)
            events=[
                aw(moment("11:52"),moment("13:01")),
                aw(moment("13:01"),moment("13:50"),afk=True),
                aw(moment("13:50"),moment("16:01")),
                aw(moment("16:01"),moment("16:17"),afk=True),
                aw(moment("16:17"),moment("17:27")),
            ]
            remote=[{"begin":moment("13:50").isoformat(),
                     "end":moment("16:01").isoformat()}]
            x=reviewable_intervals("2026-10-29",events,remote,p,NOW)
            self.assertEqual([(row["start"][11:16],row["end"][11:16])
                              for row in x],
                             [("11:52","13:01"),("16:17","17:27")])
            self.assertTrue(all(row["requires_explicit_human_confirmation"]
                                for row in x))
            self.assertTrue(all(row["counts_as_payable_hours"] is False
                                for row in x))

    def test_manual_write_permission_is_independent_and_defaults_off(self):
        with tempfile.TemporaryDirectory() as temp:
            p,path=profile(temp)
            class NoApi:
                def get(self,*args,**kwargs):
                    raise AssertionError("Network must not be called")
            with (patch("cooperator_agent.manual_review.load_dotenv",
                        return_value={"KIMAI_WRITE_ENABLED":"false",
                                      "KIMAI_API_TOKEN":"fake"}),
                  patch("cooperator_agent.manual_review.read_credentials",
                        return_value={})):
                with self.assertRaisesRegex(ValueError,"manual preview writes"):
                    submit(profile_path=path,env_path=Path(temp)/".env",
                           day="2026-10-29",start_hm="11:52",end_hm="13:01",
                           task="atividade interna",confirmed=True,now=NOW,
                           client=NoApi())

    def test_confirmation_requires_explicit_optin_and_safe_bounds(self):
        with tempfile.TemporaryDirectory() as temp:
            p,path=profile(temp)
            with self.assertRaisesRegex(ValueError,"explicitly confirm"):
                submit(profile_path=path,env_path=Path(temp)/".env",
                       day="2026-10-29",start_hm="11:52",end_hm="13:01",
                       task="desenvolvimento interno",confirmed=False,now=NOW)
            valid=self_report_window(p,"2026-10-29","11:52","13:01",
                                     "desenvolvimento interno",NOW)
            same=self_report_window(p,"2026-10-29","11:52","13:01",
                                    "desenvolvimento interno",NOW)
            self.assertEqual(valid.id,same.id)
            self.assertEqual(valid.work_seconds,0)
            self.assertEqual(valid.chat_unknown_seconds,0)
            self.assertEqual(valid.uncovered_seconds,69*60)
            with self.assertRaises(ValueError):
                bounds(p,"2026-10-29","12:00","11:00",NOW)
            with self.assertRaises(ValueError):
                bounds(p,"2026-10-29","12:00","18:00",NOW)
            with self.assertRaises(ValueError):
                bounds(p,"2026-10-29","15:00","18:00",NOW)
            with self.assertRaises(ValueError):
                bounds(p,"2026-10-16","12:00","13:00",NOW)

    def test_confirmed_manual_preview_is_idempotent_and_never_billable(self):
        with tempfile.TemporaryDirectory() as temp:
            p,path=profile(temp)
            remote=[]
            class FakeResponse:
                status_code=201
                content=b'{"id": 9001}'
                def json(self):return {"id":9001}
            class Session:
                calls=0
                def post(self,url,json=None,headers=None,timeout=None,
                         allow_redirects=None):
                    self.calls+=1
                    self_outer.assertEqual(self.calls,1)
                    self_outer.assertFalse(allow_redirects)
                    start=dt.datetime.fromisoformat(json["begin"]).replace(tzinfo=TZ)
                    end=dt.datetime.fromisoformat(json["end"]).replace(tzinfo=TZ)
                    remote.append({
                        "id":9001,"begin":start.isoformat(),"end":end.isoformat(),
                        "duration":int((end-start).total_seconds()),
                        "billable":json["billable"],"exported":json["exported"],
                        "project":json["project"],"activity":json["activity"],
                        "description":json["description"],"user":1
                    })
                    return FakeResponse()
            class Client:
                session=Session()
                base_url="https://kimai.example.test"
                token="fake-token"
                def get(self,path):
                    if path=="/users/me":
                        return {"id":1,"timezone":"America/Sao_Paulo"},{}
                    raise AssertionError("Unexpected GET")
            self_outer=self
            env={"KIMAI_WRITE_ENABLED":"false",
                 "KIMAI_MANUAL_PREVIEW_ENABLED":"true",
                 "KIMAI_BASE_URL":"https://kimai.example.test",
                 "KIMAI_API_TOKEN":"fake-token"}
            with (patch("cooperator_agent.manual_review.load_dotenv",return_value=env),
                  patch("cooperator_agent.manual_review.read_credentials",return_value={}),
                  patch("cooperator_agent.manual_review.validate_catalog",return_value=True),
                  patch("cooperator_agent.manual_review.remote_day_list",
                        side_effect=lambda *args,**kwargs:remote),
                  patch("cooperator_agent.reconcile.remote_day_list",
                        side_effect=lambda *args,**kwargs:remote)):
                args={"profile_path":path,"env_path":Path(temp)/".env",
                      "day":"2026-10-29","start_hm":"11:52","end_hm":"13:01",
                      "task":"trabalho de desenvolvimento","confirmed":True,
                      "now":NOW,"client":Client()}
                first=submit(**args)
                second=submit(**args)
            self.assertEqual(first["status"],"reconciled")
            self.assertEqual(first["remote_id"],9001)
            self.assertEqual(second["status"],"already_recorded")
            self.assertEqual(len(remote),1)
            self.assertFalse(remote[0]["billable"])
            self.assertFalse(remote[0]["exported"])
            self.assertFalse(first["payroll_approved"])
            import sqlite3
            with sqlite3.connect(p.state_dir/"ledger.sqlite3") as db:
                report=db.execute(
                    "SELECT basis,payroll_approved FROM human_declarations").fetchall()
                self.assertEqual(report,[("worker_explicit_confirmation",0)])

    def test_manual_declared_time_cannot_overlap_existing_user_time(self):
        with tempfile.TemporaryDirectory() as temp:
            p,path=profile(temp)
            row={"id":101,"begin":moment("12:00").isoformat(),
                 "end":moment("12:40").isoformat(),
                 "duration":2400,"billable":False,
                 "exported":False}
            class Client:
                def get(self,path):return {"id":1,"timezone":str(TZ)},{}
            with (patch("cooperator_agent.manual_review.load_dotenv",
                        return_value={"KIMAI_WRITE_ENABLED":"true",
                                      "KIMAI_API_TOKEN":"fake",
                                      "KIMAI_BASE_URL":"https://kimai.example"}),
                  patch("cooperator_agent.manual_review.read_credentials",return_value={}),
                  patch("cooperator_agent.manual_review.validate_catalog",return_value=True),
                  patch("cooperator_agent.manual_review.remote_day_list",
                        return_value=[row])):
                with self.assertRaisesRegex(ValueError,"over an existing"):
                    submit(profile_path=path,env_path=Path(temp)/".env",
                           day="2026-10-29",start_hm="11:52",end_hm="13:01",
                           task="atividade interna",confirmed=True,now=NOW,
                           client=Client())

if __name__=="__main__":
    unittest.main()
