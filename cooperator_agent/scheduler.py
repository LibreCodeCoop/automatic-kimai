"""OS-native per-user scheduled execution every 30 minutes.

Linux: systemd user timer with catch-up on login.
macOS: LaunchAgent RunAtLoad and StartInterval.
Windows: per-user Scheduled Tasks periodic + logon.
Never requires an administrator or a 24/7 computer. No shutdown hook is
assumed to run reliably; ActivityWatch history is replayed on next logon.
"""
from __future__ import annotations
import argparse
import os
import plistlib
import subprocess
import sys
from pathlib import Path

from .profile import Profile
from .paths import locations,private_dir

SERVICE_NAME="automatic-kimai-daily-agent"
LAUNCH_LABEL="org.automatic-kimai.agent"

def argv(profile_path:Path,env_path:Path,*,apply:bool,python:str|None=None):
    if python is None and getattr(sys,"frozen",False):
        # A packaged app cannot invoke Python's -m flag.
        args=[sys.executable,"--sync","--profile",str(profile_path),
              "--env-file",str(env_path)]
    else:
        args=[python or sys.executable,"-m","cooperator_agent.runtime",
              "--profile",str(profile_path),"--env-file",str(env_path)]
    if apply:args.append("--apply")
    return args

def user_systemd_units(profile_path:Path,env_path:Path,*,apply:bool,python=None):
    cmd=argv(profile_path,env_path,apply=apply,python=python)
    # systemd ExecStart uses whitespace-separated shell-like quoting.
    import shlex
    line=" ".join(shlex.quote(arg) for arg in cmd)
    working_dir=(str(Path(profile_path).parent) if getattr(sys,"frozen",False)
                 else str(Path(__file__).resolve().parent.parent))
    service=f"""[Unit]
Description=Automatic Kimai - local 30 minute sync
After=network-online.target

[Service]
Type=oneshot
WorkingDirectory={shlex.quote(working_dir)}
ExecStart={line}
UMask=0077
NoNewPrivileges=true
TimeoutStartSec=7min
Environment=PYTHONDONTWRITEBYTECODE=1
"""
    timer=f"""[Unit]
Description=Automatic Kimai - 30 minute sync while logged in

[Timer]
OnCalendar=*-*-* *:00,30:00
OnStartupSec=90s
Persistent=true
AccuracySec=60s
RandomizedDelaySec=10s
Unit={SERVICE_NAME}.service

[Install]
WantedBy=timers.target
"""
    return service,timer

def mac_launch_agent(profile_path:Path,env_path:Path,*,apply:bool,python=None):
    return {
        "Label":LAUNCH_LABEL,
        "ProgramArguments":argv(profile_path,env_path,apply=apply,python=python),
        "StartInterval":1800,
        "RunAtLoad":True,
        "ProcessType":"Background",
        "LimitLoadToSessionType":"Aqua",
    }

def windows_tasks(profile_path:Path,env_path:Path,*,apply:bool,python=None):
    args=argv(profile_path,env_path,apply=apply,python=python)
    # subprocess.list2cmdline correctly quotes Windows paths with spaces.
    program=subprocess.list2cmdline(args)
    return [
        ["schtasks","/create","/f","/sc","minute","/mo","30",
         "/tn","AutomaticKimai-30min","/tr",program,"/rl","LIMITED"],
        ["schtasks","/create","/f","/sc","onlogon",
         "/tn","AutomaticKimai-Login","/tr",program,"/rl","LIMITED"],
    ]

def install(profile_path:Path,env_path:Path,*,system:str|None=None,
            execute:bool=False,runner=subprocess.run,python=None):
    profile=Profile.read(profile_path)
    allow=(profile.sync_mode=="preview_nonbillable" and
           profile.allow_auto_nonbillable_preview is True)
    os_name=system or sys.platform
    if os_name.startswith("linux"):
        service,timer=user_systemd_units(profile_path,env_path,apply=allow,python=python)
        directory=Path.home()/".config"/"systemd"/"user"
        output=[directory/(SERVICE_NAME+".service"),
                directory/(SERVICE_NAME+".timer")]
        if execute:
            private_dir(directory) if not directory.exists() else None
            output[0].write_text(service,encoding="utf-8")
            output[1].write_text(timer,encoding="utf-8")
            runner(["systemctl","--user","daemon-reload"],check=True)
            runner(["systemctl","--user","enable","--now",SERVICE_NAME+".timer"],check=True)
            runner(["systemctl","--user","restart",SERVICE_NAME+".timer"],check=True)
        return {"system":"linux","files":[str(x) for x in output],
                "interval_minutes":30,"writes_opted_in":allow,
                "installed":bool(execute)}
    if os_name=="darwin":
        output=Path.home()/"Library"/"LaunchAgents"/(LAUNCH_LABEL+".plist")
        if execute:
            output.parent.mkdir(parents=True,exist_ok=True)
            if output.is_symlink():raise ValueError("Symlinked LaunchAgent")
            output.write_bytes(plistlib.dumps(mac_launch_agent(
                profile_path,env_path,apply=allow,python=python)))
            runner(["launchctl","bootout",f"gui/{os.getuid()}",str(output)],
                   check=False)
            runner(["launchctl","bootstrap",f"gui/{os.getuid()}",str(output)],check=True)
        return {"system":"darwin","files":[str(output)],
                "interval_minutes":30,"writes_opted_in":allow,
                "installed":bool(execute)}
    if os_name=="win32":
        commands=windows_tasks(profile_path,env_path,apply=allow,python=python)
        if execute:
            for command in commands:runner(command,check=True)
        return {"system":"windows","task_names":["AutomaticKimai-30min","AutomaticKimai-Login"],
                "interval_minutes":30,"writes_opted_in":allow,
                "installed":bool(execute)}
    raise ValueError("Unsupported OS for automatic scheduling")

def main():
    parser=argparse.ArgumentParser(description=__doc__)
    p=locations()
    parser.add_argument("--profile",type=Path,default=p.profile)
    parser.add_argument("--env-file",type=Path,default=p.credentials)
    parser.add_argument("--install",action="store_true",
                        help="Modify current user's OS scheduler; opt-in required")
    args=parser.parse_args()
    result=install(args.profile,args.env_file,execute=args.install)
    print("SCHEDULER",result)

if __name__=="__main__":
    main()
