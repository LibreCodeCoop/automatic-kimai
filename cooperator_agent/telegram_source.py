"""Opt-in Telegram metadata connector with per-cooperator Telethon session.

Telegram API ID/hash alone do NOT authorize access. The worker completes
phone-code and optional 2FA login locally. No chat body, name, phone or
sender identity is persisted. The user must select individual dialogs.
Selected dialog IDs are stored as SHA256, not raw identifiers.
"""
from __future__ import annotations
import asyncio
import datetime as dt
import hashlib
import json
import os
from pathlib import Path

from kimai_client import save_private_json
from .profile import Profile


class TelegramNotAuthorized(RuntimeError):
    pass

def _telethon():
    try:
        from telethon import TelegramClient,errors
        return TelegramClient,errors
    except ImportError:
        raise TelegramNotAuthorized(
            "Install optional Telegram integration: automatic-kimai[telegram]") from None

def validate_credentials(credentials):
    raw=str(credentials.get("TELEGRAM_API_ID","")).strip()
    hashval=str(credentials.get("TELEGRAM_API_HASH","")).strip()
    if not raw.isdigit() or not 1<=int(raw)<=2**31 or len(hashval)!=32 or any(
        c not in "0123456789abcdefABCDEF" for c in hashval):
        raise ValueError("Telegram requires personal API ID and 32-character API hash")
    return int(raw),hashval

def dialog_fingerprint(value):
    return hashlib.sha256(str(value).encode()).hexdigest()

def _session_path(profile):
    profile.state_dir.mkdir(mode=0o700,parents=True,exist_ok=True)
    path=profile.state_dir/"telegram-session"
    if path.is_symlink() or path.with_suffix(".session").is_symlink():
        raise ValueError("Symlinked Telegram session")
    return path

def _protect_session(path):
    for candidate in (path.with_suffix(".session"),
                      path.with_suffix(".session-journal")):
        if candidate.exists() and os.name!="nt":
            candidate.chmod(0o600)

async def authenticate(profile:Profile,credentials:dict,phone:str,code_callback,
                       password_callback):
    Client,errors=_telethon()
    api,hashval=validate_credentials(credentials)
    session=_session_path(profile)
    client=Client(str(session),api,hashval)
    await client.connect()
    try:
        if await client.is_user_authorized():return True
        if not phone or len(phone)>32:
            raise ValueError("Phone number required only during Telegram login")
        await client.send_code_request(phone)
        code=str(code_callback() or "").strip()
        if not code:raise TelegramNotAuthorized("Login code was not supplied")
        try:
            await client.sign_in(phone=phone,code=code)
        except errors.SessionPasswordNeededError:
            password=str(password_callback() or "")
            if not password:raise TelegramNotAuthorized("2FA password not supplied")
            await client.sign_in(password=password)
        if not await client.is_user_authorized():
            raise TelegramNotAuthorized("Telegram login did not complete")
        return True
    finally:
        await client.disconnect()
        _protect_session(session)

async def list_dialog_choices(profile:Profile,credentials:dict):
    Client,_=_telethon()
    api,hashval=validate_credentials(credentials)
    session=_session_path(profile)
    client=Client(str(session),api,hashval)
    await client.connect()
    try:
        if not await client.is_user_authorized():
            raise TelegramNotAuthorized("Telegram requires local phone-code login")
        rows=[]
        async for dialog in client.iter_dialogs(limit=300):
            # Titles are returned to GUI in memory, never written to cache.
            rows.append({"fingerprint":dialog_fingerprint(dialog.id),
                         "title":str(dialog.name or "Sem título")[:90]})
        return rows
    finally:
        await client.disconnect()
        _protect_session(session)

def save_watchlist(profile:Profile,fingerprints:list[str]):
    if len(fingerprints)>100 or any(
        len(h)!=64 or any(c not in "0123456789abcdef" for c in h)
        for h in fingerprints):
        raise ValueError("Invalid Telegram watchlist")
    target=profile.state_dir/"telegram_watchlist.json"
    save_private_json(target,{
        "dialog_fingerprints":sorted(set(fingerprints)),
        "manual_consent":True,
        "no_titles_or_raw_ids":True,
    })

def read_watchlist(profile:Profile):
    path=profile.state_dir/"telegram_watchlist.json"
    if not path.exists():return set()
    if path.is_symlink():raise ValueError("Symlinked Telegram watchlist")
    data=json.loads(path.read_text(encoding="utf-8"))
    if data.get("manual_consent") is not True:
        return set()
    return set(data.get("dialog_fingerprints",[]))

async def collect_telegram(profile:Profile,credentials:dict):
    Client,_=_telethon()
    api,hashval=validate_credentials(credentials)
    watched=read_watchlist(profile)
    if not watched:return {"status":"no_dialogs_selected","events":0}
    session=_session_path(profile)
    client=Client(str(session),api,hashval)
    await client.connect()
    observations=[]
    found=set()
    try:
        if not await client.is_user_authorized():
            raise TelegramNotAuthorized("Telegram session not authorized")
        async for dialog in client.iter_dialogs(limit=300):
            fingerprint=dialog_fingerprint(dialog.id)
            if fingerprint not in watched:continue
            found.add(fingerprint)
            async for msg in client.iter_messages(dialog.entity,limit=60):
                if msg.date is None:continue
                observations.append({
                    "dialog_fingerprint":fingerprint[:24],
                    "timestamp_utc":msg.date.astimezone(dt.timezone.utc).isoformat(),
                    "outgoing":bool(msg.out),
                    "message_id_hash":hashlib.sha256(
                        (fingerprint+":"+str(msg.id)).encode()).hexdigest()[:20],
                    "human_duration_verified":False,
                })
    finally:
        await client.disconnect()
        _protect_session(session)
    save_private_json(profile.cache_dir/"telegram_context.json",{
        "fetched_at":dt.datetime.now(dt.timezone.utc).isoformat(),
        "source":"Telegram metadata selected by worker",
        "observations":observations,
        "selected_dialogs":len(watched),
        "found_dialogs":len(found),
        "body_or_dialog_titles_saved":False,
        "eligible_for_sync":False,
    })
    return {"status":"ok","events":len(observations),
            "selected_dialogs":len(watched),"missing_dialogs":len(watched-found)}
