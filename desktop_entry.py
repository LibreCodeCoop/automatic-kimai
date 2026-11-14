"""Single executable dispatcher for PyInstaller desktop builds.

No raw project history or personal cache is bundled. Launch without arguments
to open setup, or with --sync/--schedule to run OS-native background tasks.
"""
from __future__ import annotations
import sys

def main():
    args=sys.argv[1:]
    if args and args[0]=="--sync":
        from cooperator_agent.runtime import main as run
        sys.argv=[sys.argv[0]]+args[1:]
        return run()
    if args and args[0]=="--schedule":
        from cooperator_agent.scheduler import main as schedule
        sys.argv=[sys.argv[0]]+args[1:]
        return schedule()
    from cooperator_agent.setup_gui import main as gui
    return gui()

if __name__=="__main__":
    raise SystemExit(main() or 0)
