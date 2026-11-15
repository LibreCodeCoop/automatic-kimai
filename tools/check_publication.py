"""Conservative public-repository checks (not a substitute for human review)."""
from __future__ import annotations
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
IGNORED_DIRS = {".git", ".venv", "build", "dist", "__pycache__",
                ".pytest_cache", ".mypy_cache", ".kimai_cache",
                ".tox", "automatic_kimai.egg-info"}
TEXT_EXT = {".py", ".php", ".md", ".toml", ".json", ".yml", ".yaml",
            ".ini", ".txt", ".js", ".mjs", ".ts", ".css", ".html", ".lock"}
PRIVATE_NAMES = {"profile.json", "sources.json", "calendar_work_context.json",
                 "nextcloud_talk_context.json", "telegram_context.json",
                 "trial_monitor.sqlite3", "latest_daily_plan.json"}
PRIVATE_EXT = (".pem", ".p12", ".pfx", ".key", ".session", ".sqlite",
               ".sqlite3", ".db", ".sqlite3-wal", ".sqlite3-shm")
# The optional local denylist is intentionally untracked. Every adopter can
# scan for their own customer names, company domains and private hostnames.
DENYLIST_PATH = ROOT / ".publication-denylist"


def private_markers() -> list[str]:
    if not DENYLIST_PATH.is_file():
        return []
    if DENYLIST_PATH.is_symlink():
        raise ValueError("Refusing a symlinked publication denylist")
    return [line.strip().casefold() for line in
            DENYLIST_PATH.read_text(encoding="utf-8").splitlines()
            if line.strip() and not line.lstrip().startswith("#")]
SECRETS = re.compile(r"-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----|"
                     r"\bghp_[A-Za-z0-9]{25,}\b|"
                     r"\bgithub_pat_[A-Za-z0-9_]{25,}\b|"
                     r"\bsk-[A-Za-z0-9]{30,}\b")


def candidates() -> list[Path]:
    try:
        cp = subprocess.run(["git", "-C", str(ROOT), "ls-files", "-z",
                             "--cached", "--others", "--exclude-standard"],
                            stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                            check=True)
        items = [ROOT / raw.decode() for raw in cp.stdout.split(b"\0") if raw]
    except (OSError, subprocess.CalledProcessError):
        items = list(ROOT.rglob("*"))
    return sorted(p for p in items if p.is_file() and
                  not any(piece in IGNORED_DIRS for piece in
                          p.relative_to(ROOT).parts))


def main() -> int:
    issues = []
    for name in ("LICENSE", "README.md", "PRIVACY.md", "SECURITY.md",
                 "CONTRIBUTING.md", "pyproject.toml",
                 ".github/workflows/ci.yml"):
        if not (ROOT / name).is_file():
            issues.append("Missing release file: " + name)
    paths = candidates()
    restricted = private_markers()
    for p in paths:
        relative = p.relative_to(ROOT)
        name = p.name.lower()
        if (name in PRIVATE_NAMES or name == ".env" or
            (name.startswith(".env.") and name != ".env.example") or
            name.endswith(PRIVATE_EXT)):
            issues.append("Sensitive filename: " + str(relative))
            continue
        if p.suffix.lower() not in TEXT_EXT:
            continue
        if p.stat().st_size > 2_000_000:
            issues.append("Large source file: " + str(relative))
            continue
        try:
            body = p.read_text(encoding="utf-8")
        except UnicodeDecodeError:
            issues.append("Invalid text encoding: " + str(relative))
            continue
        if any(marker in body.casefold() for marker in restricted):
            issues.append("Private deployment marker: " + str(relative))
        if SECRETS.search(body):
            issues.append("Potential credential/private key: " + str(relative))
    if issues:
        print("PUBLICATION CHECK FAILED")
        for issue in issues:
            print(" -", issue)
        return 1
    print("PUBLICATION CHECK PASSED:", len(paths), "source files")
    print("Human review of code ownership, license and secrets is still required.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
