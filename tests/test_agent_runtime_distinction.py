"""AI run metadata never equals worker time; no real ChatGPT integration implied."""
import datetime as dt
import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from cooperator_agent.agent_runs import AgentRuns,overlaps
from cooperator_agent.daily import collect_decisions,render_private_html
from cooperator_agent.reconcile import Ledger

from test_cooperator_agent import tm,profile,event

class AgentRuntimeDistinctionTests(unittest.TestCase):
    def test_agent_run_can_finish_while_human_is_sleeping(self):
        with tempfile.TemporaryDirectory() as tmp:
            p=profile(tmp)
            logs=AgentRuns(Path(tmp)/"private"/"agent_runs.sqlite3",p.profile_id)
            try:
                run_id=logs.start("remote_desktop_mcp",at=tm(26,23))
                self.assertEqual(logs.finish(run_id,"completed",at=tm(27,4)),"completed")
                runs=logs.intervals(tm(27,0),tm(27,8))
                self.assertEqual(len(runs),1)
                self.assertEqual(runs[0]["execution_seconds"],4*3600)
                self.assertFalse(runs[0]["counts_as_human_work"])
                self.assertEqual(runs[0]["state"],"completed")
                # No finished agent run should add an AW or Kimai timesheet.
                self.assertEqual(overlaps(type("W",(),{
                    "start":tm(27,8),"end":tm(27,9)})(),runs),0)
            finally:logs.close()

    def test_concurrent_agents_do_not_double_count_runtime_overlap(self):
        with tempfile.TemporaryDirectory() as tmp:
            p=profile(tmp)
            logs=AgentRuns(Path(tmp)/"private"/"agent_runs.sqlite3",p.profile_id)
            try:
                a=logs.start("remote_desktop_mcp",at=tm(26,22))
                b=logs.start("chatgpt_work",at=tm(26,22,20))
                logs.finish(a,"completed",at=tm(26,23))
                logs.finish(b,"completed",at=tm(26,23,20))
                runs=logs.intervals(tm(26,22),tm(27,0))
                candidate=type("Candidate",(),{
                    "start":tm(26,22),"end":tm(26,23,30)})()
                self.assertEqual(overlaps(candidate,runs),80*60)
                self.assertTrue(all(not row["counts_as_human_work"] for row in runs))
            finally:logs.close()

    def test_open_background_run_is_not_assumed_finished(self):
        with tempfile.TemporaryDirectory() as tmp:
            p=profile(tmp)
            logs=AgentRuns(Path(tmp)/"private"/"agent_runs.sqlite3",p.profile_id)
            try:
                run_id=logs.start("codex_cli",at=tm(26,23))
                rows=logs.intervals(tm(26,20),tm(27,9))
                self.assertEqual(len(rows),1)
                self.assertEqual(rows[0]["state"],"running")
                self.assertFalse(rows[0]["counts_as_human_work"])
                with self.assertRaisesRegex(ValueError,"Unknown or already"):
                    logs.finish("nonexistent-run-id-1234",at=tm(27,2))
                logs.finish(run_id,"failed",at=tm(27,0,20))
                with self.assertRaises(ValueError):
                    logs.finish(run_id,"completed",at=tm(27,0,25))
            finally:logs.close()

    def test_parallel_agent_and_desktop_session_requires_review(self):
        with tempfile.TemporaryDirectory() as tmp:
            p=profile(tmp,lookback_days=1)
            ledger=Ledger(Path(tmp)/"private"/"ledger.sqlite3",
                          user_id=17,profile_id=p.profile_id)
            try:
                # Even while the OS says not-afk, MCP may have injected input.
                aw=[event(tm(27,9),tm(27,10),
                          idle_status_known=True)]
                with patch("cooperator_agent.daily._get_events",return_value=(aw,None)):
                    baseline=collect_decisions(p,now=tm(27,10,30),remote=[],
                                               ledger=ledger,user_id=17)
                self.assertEqual(len(baseline[0]["sessions"]),1)
                self.assertEqual(baseline[0]["sessions"][0][0].action,"post")
                store=AgentRuns(Path(tmp)/"private"/"agent_runs.sqlite3",p.profile_id)
                try:
                    ref=store.start("remote_desktop_mcp",at=tm(27,9,10))
                    store.finish(ref,"completed",at=tm(27,9,58))
                    interval=store.intervals(tm(27,9),tm(27,10,30))
                finally:store.close()
                with patch("cooperator_agent.daily._get_events",return_value=(aw,None)):
                    proposed=collect_decisions(p,now=tm(27,10,30),remote=[],
                                               ledger=ledger,user_id=17,
                                               agent_intervals=interval)
                reviewed=proposed[0]["sessions"][0][0]
                self.assertEqual(reviewed.action,"review")
                self.assertEqual(reviewed.reason,
                                 "agent_execution_overlap_needs_human_attribution")
            finally:ledger.close()

    def test_no_raw_prompt_or_user_personal_data_in_telemetry(self):
        with tempfile.TemporaryDirectory() as tmp:
            p=profile(tmp)
            path=Path(tmp)/"private"/"agent_runs.sqlite3"
            store=AgentRuns(path,p.profile_id)
            try:
                task=store.start("chatgpt_work",at=tm(26,22))
                self.assertRegex(task,r"^[a-f0-9]{32}$")
                self.assertEqual(path.stat().st_mode&0o777,0o600)
                with self.assertRaises(ValueError):
                    store.start("chatgpt_work","client-secret-contains space",at=tm(26,22))
                with self.assertRaises(ValueError):
                    store.start("unsupported-agent-provider",at=tm(26,22))
            finally:store.close()
            with self.assertRaisesRegex(ValueError,"different worker"):
                AgentRuns(path,"another-cooperator")

    def test_daily_html_clearly_labels_uninstrumented_chatgpt(self):
        with tempfile.TemporaryDirectory() as tmp:
            report={
                "profile_id":"worker-one","created_at":tm(27,18).isoformat(),
                "agent_telemetry_status":"no_integration_connected",
                "agent_execution_intervals":[],
                "days":[{"day":"2026-10-27","warning":None,"sessions":[]}]
            }
            html=render_private_html(report,Path(tmp)).read_text()
            self.assertIn("no_integration_connected",html)
            self.assertIn("Interações pelo celular",html)
            self.assertIn("Tempo de processamento de IA nunca",html)

if __name__=="__main__":
    unittest.main()
