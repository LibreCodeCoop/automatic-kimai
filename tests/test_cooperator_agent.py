"""Regression coverage for reusable per-worker scheduled planner/sync."""
from __future__ import annotations
import datetime as dt
import json,tempfile,unittest
from pathlib import Path
from unittest.mock import Mock,patch
from zoneinfo import ZoneInfo
import requests

from cooperator_agent.profile import Profile
from cooperator_agent.segment import segment,Window
from cooperator_agent.reconcile import (
    Ledger,Decision,plan_one,remote_overlaps,execute,payload,snapshot,checksum,
    remote_day_list,verified,
)
from cooperator_agent.daily import collect_decisions


LOCAL=ZoneInfo("America/Sao_Paulo")
def tm(day,h,m=0):
    return dt.datetime(2026,10,day,h,m,tzinfo=LOCAL)

def profile(tmp,**kwargs):
    params={"profile_id":"worker-one","timezone":"America/Sao_Paulo",
            "cache_dir":Path(tmp)/"events","state_dir":Path(tmp)/"private",
            "customer_id":2,"project_id":41,"activity_id":25}
    params.update(kwargs)
    return Profile(**params)

def event(a,b,kind="github",**attrs):
    data={"start":a.isoformat(),"end":b.isoformat(),
          "url_kind":kind,"afk":False,"private":False}
    data.update(attrs)
    return data

def window(day=26,start=(13,45),end=(15,7),**attrs):
    begin=tm(day,*start);finish=tm(day,*end)
    values={"id":"stable-test-id","date":begin.date().isoformat(),
            "start":begin,"end":finish,"work_seconds":4200,
            "chat_unknown_seconds":0,"uncovered_seconds":300,
            "ongoing":False,"topics":("Example project",),"evidence_digest":"abc"}
    values.update(attrs)
    return Window(**values)

def remote(ident,win,description=None,**attrs):
    p=payload(win,profile("/tmp/unused"))
    a=win.start;b=win.end
    r={"id":ident,"begin":a.isoformat(),"end":b.isoformat(),
       "duration":int((b-a).total_seconds()),"user":17,
       "project":41,"activity":25,"billable":False,
       "exported":False,"description":description or p["description"]}
    r.update(attrs)
    return r


class MultiWorkerAgentTests(unittest.TestCase):
    def test_profile_config_per_cooperator_isolated_and_private(self):
        with tempfile.TemporaryDirectory() as tmp:
            p=profile(tmp)
            self.assertIsNotNone(p.validate())
            wrong=profile(tmp,sync_mode="preview_nonbillable")
            with self.assertRaisesRegex(ValueError,"opt-in"):
                wrong.validate()
            with self.assertRaises(ValueError):
                profile(tmp,profile_id="user@another").validate()
            with self.assertRaises(ValueError):
                profile(tmp,customer_id=0).validate()
            config=Path(tmp)/"profile.json"
            config.write_text(json.dumps({
                "profile_id":"other-worker","timezone":"America/Sao_Paulo",
                "cache_dir":str(Path(tmp)/"data"),
                "state_dir":str(Path(tmp)/"private2"),
                "customer_id":50,"project_id":100,"activity_id":200,
            }))
            self.assertEqual(Profile.read(config).project_id,100)

    def test_unlocked_screen_without_afk_watcher_is_not_work(self):
        with tempfile.TemporaryDirectory() as tmp:
            p=profile(tmp)
            uncertain=event(tm(26,20),tm(26,23),idle_status_known=False)
            self.assertEqual(segment("2026-10-26",[uncertain],[],p,tm(27,9)),[])
            known=event(tm(26,20),tm(26,21),idle_status_known=True)
            good=segment("2026-10-26",[known],[],p,tm(27,9))
            self.assertEqual(len(good),1)
            self.assertEqual(good[0].work_seconds,3600)

    def test_long_idle_and_personal_calendar_split_foreground(self):
        with tempfile.TemporaryDirectory() as tmp:
            p=profile(tmp)
            events=[
                event(tm(26,9),tm(26,9,45)),
                event(tm(26,9,45),tm(26,10,12),kind="none",afk=True),
                event(tm(26,10,12),tm(26,11,25)),
            ]
            result=segment("2026-10-26",events,[],p,tm(26,15))
            self.assertEqual([(x.start.strftime("%H:%M"),x.end.strftime("%H:%M")) for x in result],
                             [("09:00","09:45"),("10:12","11:25")])
            self.assertFalse(any(x.ongoing for x in result))
            cal=[{"date":"2026-10-26","all_day":False,
                  "category":"personal_excluded",
                  "start_local":tm(26,10,40).isoformat(),
                  "end_local":tm(26,11,5).isoformat()}]
            x=segment("2026-10-26",events,cal,p,tm(26,15))
            self.assertEqual([(r.start.strftime("%H:%M"),r.end.strftime("%H:%M")) for r in x],
                             [("09:00","09:45"),("10:12","10:40"),("11:05","11:25")])

    def test_missing_afk_status_is_hard_boundary_not_a_thinking_gap(self):
        with tempfile.TemporaryDirectory() as tmp:
            p=profile(tmp)
            events=[
                event(tm(26,9),tm(26,9,30),idle_status_known=True),
                event(tm(26,9,30),tm(26,9,35),idle_status_known=False),
                event(tm(26,9,35),tm(26,10,5),idle_status_known=True),
            ]
            sessions=segment("2026-10-26",events,[],p,tm(26,14))
            self.assertEqual(
                [(s.start.strftime("%H:%M"),s.end.strftime("%H:%M")) for s in sessions],
                [("09:00","09:30"),("09:35","10:05")])

    def test_afk_fragments_from_tab_changes_join_before_split(self):
        with tempfile.TemporaryDirectory() as tmp:
            p=profile(tmp,long_idle_minutes=5)
            events=[
                event(tm(26,9),tm(26,9,30),idle_status_known=True),
                event(tm(26,9,30),tm(26,9,33),kind="none",
                      idle_status_known=True,afk=True),
                event(tm(26,9,33),tm(26,9,36),kind="none",
                      idle_status_known=True,afk=True),
                event(tm(26,9,36),tm(26,10,6),idle_status_known=True),
            ]
            windows=segment("2026-10-26",events,[],p,tm(26,14))
            self.assertEqual(
                [(w.start.strftime("%H:%M"),w.end.strftime("%H:%M")) for w in windows],
                [("09:00","09:30"),("09:36","10:06")])

    def test_auto_limits_reject_uncertain_and_long_sessions(self):
        from cooperator_agent.daily import collect_decisions
        with tempfile.TemporaryDirectory() as tmp:
            p=profile(tmp,lookback_days=1,auto_min_work_minutes=7,
                      auto_max_unknown_fraction=.56,auto_max_session_hours=4,
                      min_session_minutes=30)
            db=Ledger(Path(tmp)/"private"/"db.sqlite3",
                      user_id=17,profile_id=p.profile_id)
            try:
                observed=event(tm(27,9),tm(27,9,10),
                               idle_status_known=True)
                chat=event(tm(27,9,10),tm(27,10,0),kind="chat",
                           idle_status_known=True)
                with patch("cooperator_agent.daily._get_events",
                           return_value=([observed,chat],None)):
                    good=collect_decisions(p,now=tm(27,12),remote=[],
                                           ledger=db,user_id=17)
                self.assertEqual(good[0]["sessions"][0][0].action,"post")
                too_unknown=[
                    event(tm(27,9),tm(27,9,10),idle_status_known=True),
                    event(tm(27,9,55),tm(27,10,50),kind="chat",idle_status_known=True),
                ]
                with patch("cooperator_agent.daily._get_events",
                           return_value=(too_unknown,None)):
                    uncertain=collect_decisions(p,now=tm(27,12),remote=[],
                                                ledger=db,user_id=17)
                self.assertEqual(uncertain[0]["sessions"][0][0].action,"review")
            finally:db.close()

    def test_shared_chat_only_requires_independent_evidence_before_auto(self):
        with tempfile.TemporaryDirectory() as tmp:
            p=profile(tmp)
            x=segment("2026-10-26",[event(tm(26,9),tm(26,11),kind="chat")],[],
                      p,tm(26,14))
            self.assertEqual(len(x),1)
            self.assertEqual(x[0].work_seconds,0)
            self.assertGreater(x[0].chat_unknown_seconds,100*60)

    def test_working_session_midrun_is_held_by_default(self):
        with tempfile.TemporaryDirectory() as tmp:
            p=profile(tmp)
            x=segment("2026-10-27",[event(tm(27,9),tm(27,10))],[],p,tm(27,10,5))
            self.assertEqual(len(x),1)
            self.assertTrue(x[0].ongoing)
            result=plan_one(x[0],p,[],None,user_id=17)
            self.assertEqual(result.action,"hold")
            permitted=profile(tmp,allow_snapshots=True)
            result2=plan_one(x[0],permitted,[],None,user_id=17)
            self.assertEqual(result2.action,"post")

    def test_new_then_extend_same_id_and_no_second_post(self):
        with tempfile.TemporaryDirectory() as tmp:
            p=profile(tmp,allow_snapshots=True,sync_mode="preview_nonbillable",
                      allow_auto_nonbillable_preview=True)
            first=window(end=(14,15))
            extended=window(end=(15,7))
            ledger=Ledger(Path(tmp)/"private"/"db.sqlite3",user_id=17,profile_id=p.profile_id)
            try:
                a=plan_one(first,p,[],None,user_id=17)
                self.assertEqual(a.action,"post")
                old=remote(900,first)
                calls=[[],[old]]
                with patch("cooperator_agent.reconcile.remote_day_list",side_effect=calls),patch(
                    "cooperator_agent.reconcile.post_once",return_value=900) as posted:
                    result=execute(a,client=object(),profile=p,user_id=17,ledger=ledger)
                self.assertEqual(result["action"],"post")
                posted.assert_called_once()
                previous=ledger.get(first.id)
                self.assertEqual(previous["remote_id"],900)
                b=plan_one(extended,p,[old],previous,user_id=17)
                self.assertEqual((b.action,b.remote_id),("patch",900))
                newer=remote(900,extended,description=old["description"])
                api=Mock()
                api.base_url="https://kimai.example";api.token="fake"
                api.session.patch.return_value.status_code=200
                with patch("cooperator_agent.reconcile.remote_day_list",side_effect=[[old],[newer]]),patch(
                    "cooperator_agent.reconcile.post_once") as post:
                    result=execute(b,client=api,profile=p,user_id=17,ledger=ledger)
                    self.assertEqual(result["action"],"patch")
                    post.assert_not_called()
                self.assertEqual(api.session.patch.call_args.kwargs["json"],
                                 {"end":"2026-10-26T15:07:00"})
                self.assertEqual(plan_one(extended,p,[newer],ledger.get(first.id),
                                          user_id=17).action,"unchanged")
                ledger.close()
                # Reload from private disk: state survives process restart.
                reopened=Ledger(Path(tmp)/"private"/"db.sqlite3",user_id=17,profile_id=p.profile_id)
                try:
                    self.assertEqual(reopened.get(first.id)["remote_id"],900)
                finally:reopened.close()
            finally:
                if ledger.db: # no-op if already closed
                    try:ledger.close()
                    except:pass

    def test_manual_changes_and_other_client_records_never_overwritten(self):
        with tempfile.TemporaryDirectory() as tmp:
            p=profile(tmp,allow_snapshots=True)
            x=window()
            original=remote(900,x)
            known={"remote_id":900,"remote_snapshot":snapshot(original)}
            changed={**original,"description":"Manually reviewed"}
            self.assertEqual(plan_one(x,p,[changed],known,user_id=17).action,"review")
            extra=remote(901,x,project=99,billable=True)
            self.assertEqual(plan_one(x,p,[extra],None,user_id=17).action,"review")
            future=window(end=(16,0))
            self.assertEqual(plan_one(future,p,[original,extra],known,user_id=17).action,"review")
            shortened=window(end=(14,20))
            self.assertEqual(plan_one(shortened,p,[original],known,user_id=17).action,"review")
            self.assertEqual(plan_one(x,p,[original],known,user_id=17).action,"unchanged")

    def test_timed_out_post_fails_closed_no_retry(self):
        with tempfile.TemporaryDirectory() as tmp:
            p=profile(tmp,sync_mode="preview_nonbillable",allow_auto_nonbillable_preview=True)
            w=window()
            decision=plan_one(w,p,[],None,user_id=17)
            ledger=Ledger(Path(tmp)/"priv"/"ledger.db",user_id=17,profile_id=p.profile_id)
            try:
                with patch("cooperator_agent.reconcile.remote_day_list",return_value=[]),patch(
                    "cooperator_agent.reconcile.post_once",side_effect=requests.Timeout):
                    with self.assertRaisesRegex(RuntimeError,"NO retry"):
                        execute(decision,client=object(),profile=p,user_id=17,ledger=ledger)
                with patch("cooperator_agent.reconcile.remote_day_list",return_value=[]),patch(
                    "cooperator_agent.reconcile.post_once") as post:
                    with self.assertRaisesRegex(RuntimeError,"do not retry"):
                        execute(decision,client=object(),profile=p,user_id=17,ledger=ledger)
                    post.assert_not_called()
            finally:ledger.close()


    def test_unknown_post_resolves_by_unique_get_without_reposting(self):
        from cooperator_agent.reconcile import reconcile_pending
        with tempfile.TemporaryDirectory() as tmp:
            p=profile(tmp,sync_mode="preview_nonbillable",
                      allow_auto_nonbillable_preview=True)
            w=window()
            decision=plan_one(w,p,[],None,user_id=17)
            db=Ledger(Path(tmp)/"private"/"reconcile.sqlite3",user_id=17,profile_id=p.profile_id)
            try:
                with patch("cooperator_agent.reconcile.remote_day_list",return_value=[]),patch(
                    "cooperator_agent.reconcile.post_once",side_effect=requests.Timeout):
                    with self.assertRaisesRegex(RuntimeError,"NO retry"):
                        execute(decision,client=object(),profile=p,user_id=17,ledger=db)
                found=remote(710,w)
                with patch("cooperator_agent.reconcile.remote_day_list",return_value=[found]),patch(
                    "cooperator_agent.reconcile.post_once") as post:
                    result=reconcile_pending(object(),p,17,db,[dt.date(2026,10,26)])
                    self.assertEqual(len(result["recovered"]),1)
                    self.assertFalse(result["unresolved"])
                    post.assert_not_called()
                self.assertEqual(db.get(w.id)["remote_id"],710)
                self.assertEqual(plan_one(w,p,[found],db.get(w.id),user_id=17).action,"unchanged")
            finally:db.close()

    def test_unknown_post_without_unique_get_remains_blocked(self):
        from cooperator_agent.reconcile import reconcile_pending
        with tempfile.TemporaryDirectory() as tmp:
            p=profile(tmp,sync_mode="preview_nonbillable",
                      allow_auto_nonbillable_preview=True)
            w=window()
            decision=plan_one(w,p,[],None,user_id=17)
            db=Ledger(Path(tmp)/"private"/"reconcile.sqlite3",user_id=17,profile_id=p.profile_id)
            try:
                with patch("cooperator_agent.reconcile.remote_day_list",return_value=[]),patch(
                    "cooperator_agent.reconcile.post_once",side_effect=requests.Timeout):
                    with self.assertRaisesRegex(RuntimeError,"NO retry"):
                        execute(decision,client=object(),profile=p,user_id=17,ledger=db)
                with patch("cooperator_agent.reconcile.remote_day_list",return_value=[]):
                    result=reconcile_pending(object(),p,17,db,[dt.date(2026,10,26)])
                self.assertEqual(len(result["unresolved"]),1)
                self.assertIsNone(db.get(w.id))
            finally:db.close()


    def test_daily_apply_requires_explicit_worker_optin_before_any_write(self):
        from cooperator_agent.daily import run
        with tempfile.TemporaryDirectory() as folder:
            cfg=Path(folder)/"profile.json"
            cfg.write_text(json.dumps({
                "profile_id":"worker-one","timezone":"America/Sao_Paulo",
                "cache_dir":str(Path(folder)/"cache"),
                "state_dir":str(Path(folder)/"state"),
                "customer_id":2,"project_id":41,"activity_id":25,
                "sync_mode":"review_only","allow_auto_nonbillable_preview":False
            }),encoding="utf-8")
            mocked=Mock()
            mocked.get.return_value=({"id":17,"timezone":"America/Sao_Paulo"},None)
            with patch("cooperator_agent.daily.KimaiClient",return_value=mocked),patch(
                 "cooperator_agent.daily.load_dotenv",return_value={
                     "KIMAI_BASE_URL":"https://kimai.example","KIMAI_API_TOKEN":"fake",
                     "KIMAI_WRITE_ENABLED":"true","KIMAI_AUTO_PREVIEW_OPT_IN":"true",
                 }),patch("cooperator_agent.daily.execute") as mutate:
                with self.assertRaisesRegex(ValueError,"not explicitly authorized"):
                    run(profile_path=cfg,env_path=Path(folder)/".env",apply=True,
                        now=tm(27,11))
                mutate.assert_not_called()

    def test_private_review_report_escapes_untrusted_sources(self):
        from cooperator_agent.daily import render_private_html
        with tempfile.TemporaryDirectory() as folder:
            p=Path(folder)
            report={"profile_id":"pilot","created_at":tm(27,11).isoformat(),
                    "days":[{"day":"2026-10-27","warning":"<script>alert(1)</script>",
                             "sessions":[]}]}
            file=render_private_html(report,p)
            html=file.read_text()
            self.assertNotIn("<script>",html)
            self.assertIn("&lt;script&gt;",html)
            self.assertEqual(file.stat().st_mode&0o777,0o600)

    def test_ledger_never_reused_for_another_user(self):
        with tempfile.TemporaryDirectory() as tmp:
            path=Path(tmp)/"private"/"ledger.db"
            c=Ledger(path,user_id=17,profile_id="worker-one")
            c.close()
            with self.assertRaisesRegex(ValueError,"different"):
                Ledger(path,user_id=18,profile_id="worker-one")
            self.assertEqual(path.stat().st_mode&0o777,0o600)

if __name__=="__main__":
    unittest.main()
