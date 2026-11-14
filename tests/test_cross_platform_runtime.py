"""Cross-platform 30-minute agent and per-user configuration regressions."""
from __future__ import annotations
import datetime as dt
import json
import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import Mock,patch

from zoneinfo import ZoneInfo
from kimai_client import save_private_json
from cooperator_agent.profile import Profile
from cooperator_agent.paths import locations
from cooperator_agent.providers import SOURCES,read_sources,save_sources
from cooperator_agent.scheduler import (
    user_systemd_units,mac_launch_agent,windows_tasks,install
)
from cooperator_agent.secrets import save_credentials,read_credentials
from cooperator_agent.runtime import run_once,checkpoint_days
from cooperator_agent.collector import CollectionUnavailable
from cooperator_agent.lock import acquire,release
from cooperator_agent.nextcloud_sources import _events
from cooperator_agent.aw_source import url_kind as _safe_url

TZ=ZoneInfo("America/Sao_Paulo")
NOW=dt.datetime(2026,10,28,18,30,tzinfo=TZ)

def make_profile(root,*,auto=True):
    root=Path(root)
    profile=Profile(profile_id="worker-test",timezone="America/Sao_Paulo",
                    cache_dir=root/"cache",state_dir=root/"state",
                    customer_id=2,project_id=41,activity_id=25,
                    sync_mode="preview_nonbillable" if auto else "review_only",
                    allow_auto_nonbillable_preview=auto,allow_snapshots=auto)
    profile.validate()
    path=root/"config"/"profile.json"
    save_private_json(path,{
        **{key:getattr(profile,key) for key in Profile.__dataclass_fields__
           if key not in ("cache_dir","state_dir","work_topic_hints")},
        "cache_dir":str(profile.cache_dir),"state_dir":str(profile.state_dir),
        "work_topic_hints":[],
    })
    return profile,path

class CrossPlatformRuntimeTests(unittest.TestCase):
    def test_worker_approved_nextcloud_host_is_supported_portably(self):
        from cooperator_agent.aw_source import url_kind,Interval,collect
        raw="https://cloud.example.test/call/secrettoken?session=private"
        self.assertEqual(url_kind(raw),("","other"))
        self.assertEqual(url_kind(raw,("cloud.example.test",)),
                         ("https://cloud.example.test","work_site"))
        a=NOW;b=NOW+dt.timedelta(minutes=30)
        class Reader:
            def buckets(self):
                return {"w":{"type":"currentwindow"},"a":{"type":"afkstatus"},
                        "b":{"type":"web.tab.current"}}
            def events(self,bucket,start,end):
                return {
                    "w":[Interval(a,b,{"app":"Firefox","title":"Private"})],
                    "a":[Interval(a,b,{"status":"not-afk"})],
                    "b":[Interval(a,b,{"url":raw,"title":"Private"})],
                }[bucket]
        rows,_=collect(Reader(),a,b,approved_domains=("cloud.example.test",))
        self.assertEqual(rows[0]["url"],"https://cloud.example.test")
        self.assertEqual(rows[0]["url_kind"],"work_site")
        self.assertNotIn("secrettoken",json.dumps(rows))
        self.assertNotIn("session=private",json.dumps(rows))

    def test_platform_paths_never_share_global_config(self):
        with tempfile.TemporaryDirectory() as directory:
            home=Path(directory)
            linux=locations(home=home,platform_name="linux",environ={})
            windows=locations(home=home,platform_name="win32",environ={})
            mac=locations(home=home,platform_name="darwin",environ={})
            self.assertEqual(linux.profile,home/".config"/"automatic-kimai"/"profile.json")
            self.assertEqual(windows.config_dir,home/"AppData"/"Roaming"/"AutomaticKimai")
            self.assertEqual(mac.config_dir,home/"Library"/"Application Support"/"AutomaticKimai")
            self.assertEqual(len({linux.credentials,mac.credentials,windows.credentials}),3)

    def test_schedule_templates_all_operating_systems(self):
        with tempfile.TemporaryDirectory() as tmp:
            p,path=make_profile(tmp)
            env=Path(tmp)/".env"
            service,timer=user_systemd_units(path,env,apply=True,python="/usr/bin/python3")
            self.assertIn("OnCalendar=*-*-* *:00,30:00",timer)
            self.assertIn("OnStartupSec=90s",timer)
            self.assertIn("Persistent=true",timer)
            self.assertIn("cooperator_agent.runtime",service)
            self.assertIn("--apply",service)
            self.assertNotIn("03:00",timer)
            mac=mac_launch_agent(path,env,apply=True,python="/usr/bin/python3")
            self.assertEqual(mac["StartInterval"],1800)
            self.assertIs(mac["RunAtLoad"],True)
            self.assertIn("--apply",mac["ProgramArguments"])
            win=windows_tasks(path,env,apply=True,python=r"C:\Python 3\python.exe")
            self.assertEqual(len(win),2)
            self.assertIn("minute",win[0])
            self.assertIn("30",win[0])
            self.assertIn("onlogon",win[1])
            self.assertIn("--apply",win[0][-3])
            for system in ("linux","darwin","win32"):
                out=install(path,env,system=system,execute=False)
                self.assertEqual(out["interval_minutes"],30)
                self.assertFalse(out["installed"])
                self.assertTrue(out["writes_opted_in"])
            safe,safe_path=make_profile(Path(tmp)/"safe",auto=False)
            plain=user_systemd_units(safe_path,env,apply=False)[0]
            self.assertNotIn("--apply",plain)

    def test_checkpoint_after_poweroff_recovers_up_to_one_week(self):
        with tempfile.TemporaryDirectory() as tmp:
            p,path=make_profile(tmp)
            results=[]
            def collector(profile,*,now,days):
                results.append(days)
                return {"2026-10-28":{"status":"stored","events":24}}
            def reconcile(**kwargs):
                self.assertTrue(kwargs["apply"])
                return {"days":[{"sessions":[{"action":"post",
                         "result":{"status":"reconciled"}}]}]}
            e=Path(tmp)/".env"
            first=run_once(profile_path=path,env_path=e,apply=True,now=NOW,
                           collector=collector,reconciler=reconcile)
            self.assertEqual(first["remote_writes"],1)
            self.assertEqual(first["lookback_days"],3)
            second=run_once(profile_path=path,env_path=e,apply=True,
                            now=NOW+dt.timedelta(days=4),
                            collector=collector,reconciler=reconcile)
            self.assertEqual(second["lookback_days"],5)
            self.assertEqual(results,[3,5])
            self.assertFalse(second["older_backlog_needs_manual_review"])
            third=run_once(profile_path=path,env_path=e,apply=True,
                           now=NOW+dt.timedelta(days=13),
                           collector=collector,reconciler=reconcile)
            self.assertEqual(third["lookback_days"],7)
            self.assertTrue(third["older_backlog_needs_manual_review"])

    def test_poweroff_or_no_activitywatch_keeps_previous_checkpoint(self):
        with tempfile.TemporaryDirectory() as tmp:
            p,path=make_profile(tmp)
            last=run_once(profile_path=path,env_path=Path(tmp)/".env",
                          apply=False,now=NOW,collector=lambda *a,**k:{"ok":1},
                          reconciler=lambda **kwargs:{"days":[]})
            saved=last["last_successful_utc"]
            def unavailable(*args,**kwargs):
                raise CollectionUnavailable("ActivityWatch switched off")
            with self.assertRaises(CollectionUnavailable):
                run_once(profile_path=path,env_path=Path(tmp)/".env",apply=True,
                         now=NOW+dt.timedelta(hours=1),collector=unavailable,
                         reconciler=lambda **kwargs:self.fail("should not publish"))
            status=json.loads((p.state_dir/"last_cycle_status.json").read_text())
            self.assertEqual(status["last_successful_utc"],saved)
            self.assertEqual(status["result"],"offline_or_collection_unavailable")

    def test_capture_only_never_attempts_kimai_mutation(self):
        with tempfile.TemporaryDirectory() as tmp:
            p,path=make_profile(tmp)
            result=run_once(profile_path=path,env_path=Path(tmp)/".env",
                            apply=True,capture_only=True,now=NOW,
                            collector=lambda *args,**kwargs:{"events":1},
                            reconciler=lambda **kwargs:self.fail("must not call Kimai"))
            self.assertEqual(result["result"],"capture_only")
            self.assertFalse(result["attempted_publication"])

    def test_source_optin_and_unavailable_provider_blocked(self):
        with tempfile.TemporaryDirectory() as tmp:
            p,path=make_profile(tmp)
            self.assertTrue(read_sources(path)["activitywatch"])
            self.assertFalse(read_sources(path)["telegram"])
            config=save_sources(path,{"activitywatch":True,"telegram":True,
                                      "nextcloud_calendar":True,"github":True})
            self.assertTrue(config["nextcloud_calendar"])
            self.assertTrue(config["telegram"])
            self.assertEqual(read_sources(path)["github"],True)
            with self.assertRaises(ValueError):
                save_sources(path,{"activitywatch":False})

    def test_linux_credentials_private_and_never_in_profile(self):
        with tempfile.TemporaryDirectory() as tmp:
            p,path=make_profile(tmp)
            secret=Path(tmp)/"config"/".env"
            with patch("cooperator_agent.secrets._keyring",return_value=None):
                save_credentials(secret,"worker-test",{
                    "KIMAI_BASE_URL":"https://kimai.example",
                    "KIMAI_API_TOKEN":"test-secret",
                    "KIMAI_AUTO_PREVIEW_OPT_IN":"true"},
                    platform_name="linux")
                got=read_credentials(secret,profile_id="worker-test")
            self.assertEqual(got["KIMAI_API_TOKEN"],"test-secret")
            self.assertEqual(secret.stat().st_mode & 0o777,0o600)
            self.assertNotIn("test-secret",path.read_text())
            with patch("cooperator_agent.secrets._keyring",return_value=None):
                with self.assertRaisesRegex(RuntimeError,"keyring"):
                    save_credentials(secret,"worker-test",{"KIMAI_API_TOKEN":"should-not-save"},
                                     platform_name="win32")

    def test_first_run_can_save_manual_review_optin_without_error(self):
        # The GUI always sends this flag, including when unchecked. The
        # credential allowlist must accept it on a brand-new installation.
        with tempfile.TemporaryDirectory() as tmp:
            target=Path(tmp)/"config"/".env"
            from cooperator_agent.secrets import ALLOWED_KEYS
            self.assertIn("KIMAI_MANUAL_PREVIEW_ENABLED",ALLOWED_KEYS)
            with patch("cooperator_agent.secrets._keyring",return_value=None):
                save_credentials(target,"worker-test",{
                    "KIMAI_BASE_URL":"https://kimai.example",
                    "KIMAI_API_TOKEN":"test-secret",
                    "KIMAI_WRITE_ENABLED":"false",
                    "KIMAI_SYNC_MODE":"review_only",
                    "KIMAI_AUTO_PREVIEW_OPT_IN":"false",
                    "KIMAI_MANUAL_PREVIEW_ENABLED":"true",
                    "NEXTCLOUD_URL":"","NEXTCLOUD_USERNAME":"",
                    "TELEGRAM_API_ID":"",
                },platform_name="linux")
                saved=read_credentials(target,profile_id="worker-test")
            self.assertEqual(saved["KIMAI_MANUAL_PREVIEW_ENABLED"],"true")
            self.assertEqual(saved["KIMAI_API_TOKEN"],"test-secret")

    def test_gui_does_not_erase_existing_legacy_source_settings(self):
        with tempfile.TemporaryDirectory() as tmp:
            secret=Path(tmp)/".env"
            secret.write_text("KIMAI_BASE_URL=https://kimai.example\\n"
                              "KIMAI_API_TOKEN=old-token\\n"
                              "CUSTOM_TELEGRAM_SCAN_ENABLED=true\\n")
            # Test data above uses real line breaks in the file.
            secret.write_text(secret.read_text().replace("\\n","\n"))
            secret.chmod(0o600)
            with patch("cooperator_agent.secrets._keyring",return_value=None):
                save_credentials(secret,"worker-test",{
                    "KIMAI_API_TOKEN":"updated-token"},
                    platform_name="linux")
                cfg=read_credentials(secret,profile_id="worker-test")
            self.assertEqual(cfg["KIMAI_API_TOKEN"],"updated-token")
            self.assertIn("CUSTOM_TELEGRAM_SCAN_ENABLED=true",secret.read_text())

    def test_opengl_safety_no_mouse_motion_assumed(self):
        from cooperator_agent.segment import segment
        p=Profile(profile_id="worker-test",timezone="America/Sao_Paulo",
                  cache_dir=Path("/tmp/cache"),state_dir=Path("/tmp/state"),
                  customer_id=2,project_id=41,activity_id=25)
        raw=[{"start":NOW.isoformat(),
              "end":(NOW+dt.timedelta(hours=2)).isoformat(),
              "afk":True,"idle_status_known":True,"url_kind":"github"}]
        self.assertEqual(segment("2026-10-28",raw,[],p,NOW+dt.timedelta(hours=3)),[])

    def test_chatgpt_claude_browsers_are_metadata_only(self):
        self.assertEqual(_safe_url("https://claude.ai/chat/abc123?secret=123"),
                         ("https://claude.ai","chat"))
        self.assertEqual(_safe_url("https://github.com/example-org/proj/issues/10?token=no"),
                         ("https://github.com/example-org/proj/issues/10","github"))
        self.assertEqual(_safe_url("https://another-site.example/private/x"),
                         ("","other"))
        self.assertEqual(_safe_url("https://chatgpt.com/c/secret"),
                         ("https://chatgpt.com","chat"))

    def test_nextcloud_calendar_event_metadata_no_titles(self):
        ics="BEGIN:VCALENDAR\nBEGIN:VEVENT\nDTSTART:20261028T143000Z\nDTEND:20261028T153000Z\nSUMMARY:Dentista pessoal - segredo\nEND:VEVENT\nEND:VCALENDAR"
        rows=_events(ics,TZ)
        self.assertEqual(len(rows),1)
        self.assertEqual(rows[0]["category"],"personal_excluded")
        self.assertNotIn("segredo",json.dumps(rows))
        self.assertEqual(rows[0]["start_local"][11:16],"11:30")

    def test_personal_calendar_recurring_sessions_and_exdates(self):
        ics=(
          "BEGIN:VCALENDAR\nBEGIN:VEVENT\n"
          "DTSTART;TZID=America/Sao_Paulo:20261026T090000\n"
          "DTEND;TZID=America/Sao_Paulo:20261026T100000\n"
          "RRULE:FREQ=DAILY;COUNT=5\n"
          "EXDATE;TZID=America/Sao_Paulo:20261028T090000\n"
          "SUMMARY:Consulta médica pessoal\nEND:VEVENT\nEND:VCALENDAR"
        )
        start=dt.date(2026,10,26)
        end=dt.date(2026,10,31)
        rows=_events(ics,TZ,start,end)
        self.assertEqual(len(rows),4)
        self.assertEqual({r["start_local"][:10] for r in rows},
                         {"2026-10-26","2026-10-27","2026-10-29","2026-10-30"})
        self.assertTrue(all(r["category"]=="personal_excluded" for r in rows))

    def test_calendar_modified_occurrences_fail_closed(self):
        ics=(
          "BEGIN:VCALENDAR\nBEGIN:VEVENT\n"
          "DTSTART:20261028T143000Z\n"
          "DTEND:20261028T153000Z\n"
          "RECURRENCE-ID:20261028T143000Z\n"
          "SUMMARY:Consulta pessoal\nEND:VEVENT\nEND:VCALENDAR"
        )
        with self.assertRaisesRegex(RuntimeError,"review"):
            _events(ics,TZ,dt.date(2026,10,27),dt.date(2026,10,30))

    def test_cross_platform_lock_serializes_runs(self):
        with tempfile.TemporaryDirectory() as tmp:
            file=Path(tmp)/"running.lock"
            one=acquire(file)
            try:
                with self.assertRaises((OSError,BlockingIOError)):
                    acquire(file)
            finally:release(one)
            two=acquire(file)
            release(two)


    def test_portable_activitywatch_reader_never_keeps_private_urls(self):
        from cooperator_agent.aw_source import Interval,collect
        a=NOW
        b=NOW+dt.timedelta(minutes=40)
        windows=[Interval(a,b,{"app":"firefox","title":"Cliente secreto"})]
        idle=[Interval(a,b,{"status":"not-afk"})]
        web=[Interval(a,b,{"url":"https://claude.ai/chat/secret123456?token=do-not-save",
                            "title":"Projeto pessoal"})]
        class Reader:
            def buckets(self):
                return {"window":{"type":"currentwindow"},
                        "afk":{"type":"afkstatus"},
                        "browser":{"type":"web.tab.current"}}
            def events(self,bucket,start,end):
                return {"window":windows,"afk":idle,"browser":web}[bucket]
        result,stats=collect(Reader(),a,b)
        self.assertEqual(stats["sanitized_blocks"],1)
        self.assertEqual(result[0]["url_kind"],"chat")
        self.assertEqual(result[0]["url"],"https://claude.ai")
        self.assertTrue(result[0]["idle_status_known"])
        self.assertEqual(result[0]["chat_context"]["app_context"],"claude_browser")
        self.assertNotIn("secreto",json.dumps(result))
        self.assertNotIn("token=do-not-save",json.dumps(result))

    def test_portable_activitywatch_reader_fails_when_afk_missing(self):
        from cooperator_agent.aw_source import Interval,collect,ActivityWatchError
        a=NOW
        class Reader:
            def buckets(self):
                return {"w":{"type":"currentwindow"}}
            def events(self,bucket,start,end):
                return [Interval(a,a+dt.timedelta(minutes=15),{"app":"firefox"})]
        with self.assertRaises(ActivityWatchError):
            collect(Reader(),a,a+dt.timedelta(minutes=15))

    def test_trial_metrics_for_one_week_without_customer_data(self):
        from cooperator_agent.trial import record_cycle,weekly_summary
        with tempfile.TemporaryDirectory() as tmp:
            f=Path(tmp)/"trial.sqlite3"
            record_cycle(f,{"at_local":NOW.isoformat(),"result":"success",
                            "remote_writes":1,"review_count":3,
                            "lookback_days":3,"attempted_publication":True})
            initial=weekly_summary(f,NOW+dt.timedelta(days=3))
            self.assertEqual(initial["state"],"pilot_running")
            self.assertEqual((initial["writes"],initial["reviews"]),(1,3))
            finished=weekly_summary(f,NOW+dt.timedelta(days=8))
            self.assertEqual(finished["state"],"review_due")
            self.assertFalse(finished["financially_approved"])

    def test_app_profile_defaults_to_portable_backend(self):
        with tempfile.TemporaryDirectory() as tmp:
            p,path=make_profile(tmp)
            self.assertEqual(Profile.read(path).collector_backend,"portable")
            data=json.loads(path.read_text())
            data["collector_backend"]="legacy"
            save_private_json(path,data)
            with self.assertRaisesRegex(ValueError,"only the portable"):
                Profile.read(path)
if __name__=="__main__":
    unittest.main()
