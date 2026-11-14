"""Per-user filesystem paths on Windows, macOS and Linux.

Never put credentials or evidence in the installed application directory.
"""
from __future__ import annotations
import os
import sys
from dataclasses import dataclass
from pathlib import Path


@dataclass(frozen=True)
class Locations:
    config_dir: Path
    state_dir: Path
    cache_dir: Path
    profile: Path
    credentials: Path


def locations(*, home:Path|None=None, platform_name:str|None=None,
              environ:dict|None=None)->Locations:
    env=os.environ if environ is None else environ
    system=platform_name or sys.platform
    base=Path(home or Path.home())
    if system=="win32":
        config=Path(env.get("APPDATA") or (base/"AppData"/"Roaming"))/"AutomaticKimai"
        state=Path(env.get("LOCALAPPDATA") or (base/"AppData"/"Local"))/"AutomaticKimai"
        cache=state/"cache"
    elif system=="darwin":
        config=base/"Library"/"Application Support"/"AutomaticKimai"
        state=config/"state"
        cache=base/"Library"/"Caches"/"AutomaticKimai"
    else:
        config=Path(env.get("XDG_CONFIG_HOME") or (base/".config"))/"automatic-kimai"
        state=Path(env.get("XDG_STATE_HOME") or (base/".local"/"state"))/"automatic-kimai"
        cache=Path(env.get("XDG_CACHE_HOME") or (base/".cache"))/"automatic-kimai"
    return Locations(config,state,cache,config/"profile.json",config/".env")


def private_dir(path:Path):
    target=Path(path)
    if target.is_symlink():
        raise ValueError("Refusing symlinked account directory")
    target.mkdir(parents=True,exist_ok=True,mode=0o700)
    if os.name!="nt" and target.stat().st_mode&0o077:
        raise ValueError("Account directory is group/world accessible")
    return target
