"""No silent omission of own Talk actions on a remote/AFK workday."""
import datetime as dt
import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch
from zoneinfo import ZoneInfo

from kimai_client import save_private_json
from cooperator_agent.coverage import local_action_anchors,uncovered_activity
from cooperator_agent.profile import Profile
from cooperator_agent.nextcloud_sources import collect_talk

Z=ZoneInfo("America/Sao_Paulo")

def date(hour):
    return dt.datetime.fromisoformat("2026-10-28T"+hour+":00-03:00")

def profile(tmp):
    p=Profile(profile_id="worker-a",timezone="America/Sao_Paulo",
              cache_dir=Path(tmp)/"cache",state_dir=Path(tmp)/"state",
              customer_id=2,project_id=41,activity_id=25)
    p.validate()
    return p

class ExternalActionCoverageTests(unittest.TestCase):
    def test_outgoing_actions_with_pc_afk_are_review_not_hours(self):
        with tempfile.TemporaryDirectory() as tmp:
            p=profile(tmp)
            save_private_json(p.cache_dir/"nextcloud_talk_context.json",{
                "observations":[{"message_events":[
                    {"utc":date("15:15").isoformat(),"outgoing":True},
                    {"utc":date("15:30").isoformat(),"outgoing":False},
                    {"utc":date("15:45").isoformat(),"outgoing":True},
                ]}],
            })
            anchors=local_action_anchors(p)
            self.assertEqual(len(anchors),2)
            all_afk=[{"start":date("14:00").isoformat(),
                     "end":date("17:00").isoformat(),
                     "idle_status_known":True,"afk":True,
                     "private":False,"url_kind":"none"}]
            gaps=uncovered_activity("2026-10-28",all_afk,[],p,
                                     date("23:00"),action_anchors=anchors)
            self.assertEqual(len(gaps),1)
            self.assertEqual(gaps[0]["reason"],"remote_actions_without_measured_duration")
            self.assertEqual(gaps[0]["outgoing_external_actions"],2)
            self.assertEqual(gaps[0]["observed_nonafk_minutes"],0)
            self.assertFalse(gaps[0]["human_duration_verified"])

    def test_talk_reader_disables_read_markers_and_persists_only_timestamps(self):
        import datetime as dt2
        now=dt2.datetime.now(dt2.timezone.utc)
        instant=int(now.timestamp())
        class Response:
            status_code=200
            content=b"{}"
            def __init__(self,data):self.data=data
            def json(self):
                return {"ocs":{"meta":{"statuscode":200},"data":self.data}}
        class Session:
            calls=[]
            def get(self,url,params=None,headers=None,timeout=None,allow_redirects=None):
                self.calls.append((url,params,allow_redirects))
                if url.endswith("/cloud/user"):return Response({"id":"worker"})
                if url.endswith("/api/v4/room"):
                    return Response([{"token":"roomabc123","lastActivity":instant,
                                     "hasCall":False}])
                if url.endswith("/chat/roomabc123"):
                    return Response([
                        {"actorId":"worker","timestamp":instant,
                         "message":"private text must never be persisted"},
                        {"actorId":"another","timestamp":instant,"message":"other private text"},
                    ])
                raise AssertionError("Unexpected API route")
        with tempfile.TemporaryDirectory() as tmp:
            p=profile(tmp)
            with patch("cooperator_agent.nextcloud_sources.requests.Session",Session):
                result=collect_talk(p,{
                    "NEXTCLOUD_URL":"https://example.nextcloud.test",
                    "NEXTCLOUD_USERNAME":"worker","NEXTCLOUD_APP_PASSWORD":"fake-app-token"
                })
                self.assertEqual(result["own_action_timestamps"],1)
                data=(p.cache_dir/"nextcloud_talk_context.json").read_text()
                self.assertNotIn("private text",data)
                self.assertNotIn("fake-app-token",data)
                parsed=json.loads(data)
                self.assertEqual(len(parsed["own_actions"]),1)
            requests=Session.calls
            for url,params,redirect in requests:
                self.assertFalse(redirect)
                if "/chat/" in url:
                    self.assertEqual(params["setReadMarker"],0)
                    self.assertEqual(params["markNotificationsAsRead"],0)
                    self.assertEqual(params["noStatusUpdate"],1)

if __name__=="__main__":
    unittest.main()
