"""Credentials separated from JSON profiles, with OS keyring if present.

On Windows/macOS keyring is mandatory for new secrets (never plaintext fallback).
On Linux private .env is accepted for compatibility with the existing pilot.
The application never displays an existing token or sends it to a third party.
"""
from __future__ import annotations
import os,sys
from pathlib import Path
from kimai_client import load_dotenv
from .paths import private_dir

ALLOWED_KEYS=frozenset({
    "KIMAI_BASE_URL","KIMAI_API_TOKEN","KIMAI_WRITE_ENABLED",
    "KIMAI_SYNC_MODE","KIMAI_AUTO_PREVIEW_OPT_IN",
    "KIMAI_MANUAL_PREVIEW_ENABLED",
    "NEXTCLOUD_URL","NEXTCLOUD_USERNAME","NEXTCLOUD_APP_PASSWORD",
    "TELEGRAM_API_ID","TELEGRAM_API_HASH",
    "GITHUB_TOKEN","OPENAI_API_KEY","ANTHROPIC_API_KEY"
})
SECRET_KEYS=frozenset({"KIMAI_API_TOKEN","NEXTCLOUD_APP_PASSWORD",
                        "TELEGRAM_API_HASH","GITHUB_TOKEN",
                        "OPENAI_API_KEY","ANTHROPIC_API_KEY"})
SERVICE="AutomaticKimai"


def _keyring():
    try:
        import keyring
        return keyring
    except ImportError:
        return None


def read_credentials(path:Path,*,profile_id:str,platform_name:str|None=None):
    public=load_dotenv(Path(path))
    result={k:v for k,v in public.items() if k in ALLOWED_KEYS}
    ring=_keyring()
    if ring:
        for key in SECRET_KEYS:
            try:
                value=ring.get_password(SERVICE,profile_id+":"+key)
            except Exception:
                value=None
            if value:result[key]=value
    return result


def save_credentials(path:Path,profile_id:str,values:dict[str,str],
                     *,platform_name:str|None=None):
    system=platform_name or sys.platform
    if any(k not in ALLOWED_KEYS for k in values):
        raise ValueError("Unknown integration credential key")
    target=Path(path)
    private_dir(target.parent)
    if target.is_symlink():raise ValueError("Symlinked credential file")
    prior=load_dotenv(target)
    ring=_keyring()
    secret_updates={k:str(v) for k,v in values.items() if k in SECRET_KEYS and v}
    if system in ("darwin","win32") and secret_updates and not ring:
        raise RuntimeError("Install the Python keyring package for secure credentials on macOS/Windows")
    if ring and secret_updates:
        for k,v in secret_updates.items():
            ring.set_password(SERVICE,profile_id+":"+k,v)
    # Preserve unrelated, already configured legacy collectors in the pilot.
    # The wizard must never silently destroy Nextcloud/Telegram settings
    # which may still be used by a separate consented local service.
    public=dict(prior)
    for k,v in values.items():
        if k not in SECRET_KEYS and v is not None:
            public[k]=str(v)
    if system not in ("darwin","win32") and not ring:
        # Legacy Linux compatibility. A private file is required.
        public.update(secret_updates)
    else:
        for k in SECRET_KEYS:public.pop(k,None)
    text="".join(k+"="+str(v).replace("\r","").replace("\n","")+"\n"
                 for k,v in sorted(public.items()))
    temp=target.with_suffix(target.suffix+".tmp")
    fd=os.open(temp,os.O_CREAT|os.O_EXCL|os.O_WRONLY,0o600)
    try:
        with os.fdopen(fd,"w",encoding="utf-8") as f:f.write(text)
        os.replace(temp,target)
        if os.name!="nt":target.chmod(0o600)
    finally:
        if temp.exists():temp.unlink()
    return target
