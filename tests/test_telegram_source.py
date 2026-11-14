"""Per-worker Telegram session, privacy and opt-in tests; no live Telegram login."""
import asyncio
import datetime as dt
import hashlib
import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from cooperator_agent.profile import Profile
from cooperator_agent.telegram_source import (
    TelegramNotAuthorized, validate_credentials, dialog_fingerprint,
    save_watchlist, read_watchlist, _session_path,
    collect_telegram,
)
from cooperator_agent.providers import save_sources,read_sources


def profile(base):
    return Profile(profile_id="telegram-worker",timezone="America/Sao_Paulo",
                   cache_dir=Path(base)/"cache",
                   state_dir=Path(base)/"state",
                   customer_id=2,project_id=41,activity_id=25)


class TelegramPortableTests(unittest.TestCase):
    def test_api_credentials_validation_and_no_auth_from_hash_alone(self):
        params={"TELEGRAM_API_ID":"12345","TELEGRAM_API_HASH":"a"*32}
        self.assertEqual(validate_credentials(params),(12345,"a"*32))
        for bad in (
            {"TELEGRAM_API_ID":"0","TELEGRAM_API_HASH":"a"*32},
            {"TELEGRAM_API_ID":"abc","TELEGRAM_API_HASH":"a"*32},
            {"TELEGRAM_API_ID":"123","TELEGRAM_API_HASH":"my-secret"},
            {"TELEGRAM_API_ID":"123","TELEGRAM_API_HASH":"z"*32}
        ):
            with self.assertRaises(ValueError):
                validate_credentials(bad)

    def test_watched_dialogs_are_hashed_and_private(self):
        with tempfile.TemporaryDirectory() as root:
            p=profile(root)
            target=dialog_fingerprint(12345)
            self.assertEqual(target,hashlib.sha256(b"12345").hexdigest())
            save_watchlist(p,[target,target])
            path=p.state_dir/"telegram_watchlist.json"
            data=path.read_text()
            self.assertNotIn("12345",data)
            self.assertEqual(read_watchlist(p),{target})
            self.assertEqual(path.stat().st_mode&0o777,0o600)
            with self.assertRaises(ValueError):
                save_watchlist(p,["12345"])
            with self.assertRaises(ValueError):
                save_watchlist(p,["a"*64]*101)

    def test_not_selected_does_not_touch_telegram_api(self):
        with tempfile.TemporaryDirectory() as root:
            p=profile(root)
            with patch("cooperator_agent.telegram_source._telethon",
                       return_value=(object,object)):
                result=asyncio.run(collect_telegram(p,{
                    "TELEGRAM_API_ID":"12345","TELEGRAM_API_HASH":"a"*32}))
            self.assertEqual(result["status"],"no_dialogs_selected")
            self.assertEqual(result["events"],0)
            self.assertFalse((p.cache_dir/"telegram_context.json").exists())

    def test_telegram_toggle_starts_disabled(self):
        with tempfile.TemporaryDirectory() as root:
            path=Path(root)/"profile.json"
            self.assertFalse(read_sources(path)["telegram"])
            saved=save_sources(path,{"telegram":True,"activitywatch":True})
            self.assertTrue(saved["telegram"])
            self.assertTrue(read_sources(path)["telegram"])


if __name__=="__main__":
    unittest.main()
