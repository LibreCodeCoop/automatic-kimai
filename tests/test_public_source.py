"""Source-publishing regressions; no real credentials or network calls."""
from __future__ import annotations

import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from zoneinfo import ZoneInfo

from cooperator_agent.profile import Profile
from cooperator_agent.scheduler import LAUNCH_LABEL, mac_launch_agent
from kimai_client import normalize_base_url, KimaiConfigError

ROOT = Path(__file__).resolve().parents[1]


class PublicSourceTests(unittest.TestCase):
    def test_private_deployment_denylist_is_optional_and_untracked(self):
        from unittest.mock import patch
        from tools import check_publication
        with tempfile.TemporaryDirectory() as tmp:
            file = Path(tmp) / "denylist"
            file.write_text("# Private deployment markers\nprivate.example.invalid\n")
            with patch.object(check_publication, "DENYLIST_PATH", file):
                self.assertEqual(check_publication.private_markers(),
                                 ["private.example.invalid"])
            with patch.object(check_publication, "DENYLIST_PATH",
                              Path(tmp) / "missing"):
                self.assertEqual(check_publication.private_markers(), [])

    def test_publication_guard_passes(self):
        result = subprocess.run(
            [sys.executable, str(ROOT / "tools/check_publication.py")],
            cwd=ROOT, capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    def test_arbitrary_user_organization_and_timezone(self):
        with tempfile.TemporaryDirectory() as tmp:
            base = Path(tmp)
            p = Profile(profile_id="employee-7", timezone="Europe/Paris",
                        cache_dir=base/"cache", state_dir=base/"state",
                        customer_id=8, project_id=22, activity_id=99)
            self.assertEqual(p.validate(), p)
            self.assertEqual(p.zone, ZoneInfo("Europe/Paris"))
            self.assertEqual(
                normalize_base_url("https://times.example.net/kimai"),
                "https://times.example.net/kimai",
            )

    def test_public_package_does_not_call_unshipped_pilot(self):
        import inspect
        import cooperator_agent.collector as collector
        self.assertNotIn("aw_collector", inspect.getsource(collector))
        with tempfile.TemporaryDirectory() as tmp:
            base = Path(tmp)
            with self.assertRaisesRegex(ValueError, "only the portable"):
                Profile(profile_id="employee-7", timezone="Europe/Paris",
                        cache_dir=base/"cache", state_dir=base/"state",
                        customer_id=8, project_id=22, activity_id=99,
                        collector_backend="legacy").validate()

    def test_scheduler_label_is_project_neutral(self):
        self.assertEqual(LAUNCH_LABEL, "org.automatic-kimai.agent")
        result = mac_launch_agent(
            Path("/tmp/profile.json"), Path("/tmp/credentials"),
            apply=False, python="/usr/bin/python3")
        self.assertEqual(result["Label"], LAUNCH_LABEL)
        self.assertNotIn("--apply", result["ProgramArguments"])

    def test_timezone_default_comes_from_os_not_one_organization(self):
        from unittest.mock import patch
        from cooperator_agent.setup_gui import system_timezone_name
        with patch("cooperator_agent.setup_gui.get_localzone_name",
                   return_value="Asia/Tokyo"):
            self.assertEqual(system_timezone_name(), "Asia/Tokyo")
        with patch("cooperator_agent.setup_gui.get_localzone_name",
                   return_value="Invalid/Timezone"):
            self.assertEqual(system_timezone_name(), "UTC")

    def test_public_license_and_guide_exist(self):
        self.assertTrue((ROOT/"LICENSE").is_file())
        self.assertTrue((ROOT/"README.md").is_file())
        self.assertTrue((ROOT/"PRIVACY.md").is_file())
        self.assertTrue((ROOT/"GUIA_INICIO_COOPERADOS.md").is_file())
        import tomllib
        package = tomllib.loads((ROOT/"pyproject.toml").read_text())
        self.assertEqual(package["project"]["license"], "GPL-3.0-or-later")
        self.assertEqual(package["project"]["version"], "0.3.0a1")


if __name__ == "__main__":
    unittest.main()
