"""Read-only Kimai REST client. No POST/PATCH/PUT/DELETE methods are implemented.

The .env file is parsed, never executed as shell code.
See https://www.kimai.org/documentation/rest-api.html and api-pagination.html.
"""
from __future__ import annotations

import json
import os
import re
import urllib.parse
from datetime import datetime, timezone
from pathlib import Path

import requests


class KimaiConfigError(Exception):
    """Invalid local setup; credentials are never included in messages."""


class KimaiAPIError(Exception):
    """Non-secret transport or protocol failure."""


def load_dotenv(path: Path) -> dict[str, str]:
    """Parse minimal KEY=VALUE lines without expanding variables or executing shell."""
    if not path.exists():
        return {}
    if path.is_symlink():
        raise KimaiConfigError("Refusing a symlinked .env file")
    mode = path.stat().st_mode & 0o777
    if os.name != "nt" and mode & 0o077:
        raise KimaiConfigError(".env must not be accessible to group or others (chmod 600 .env)")
    # Windows new deployments must keep API secrets in Credential Manager
    # through the keyring backend. A plain .env may contain only non-secrets.
    result: dict[str, str] = {}
    for number, original in enumerate(path.read_text(encoding="utf-8").splitlines(), 1):
        line = original.strip()
        if not line or line.startswith("#"):
            continue
        if line.startswith("export "):
            line = line[7:].strip()
        if "=" not in line:
            raise KimaiConfigError(f"Invalid .env format on line {number}")
        key, value = line.split("=", 1)
        key = key.strip()
        value = value.strip()
        if not re.fullmatch(r"[A-Za-z_][A-Za-z0-9_]*", key):
            raise KimaiConfigError(f"Invalid .env variable on line {number}")
        if value.startswith(("'", '"')):
            if len(value) < 2 or value[-1] != value[0]:
                raise KimaiConfigError(f"Unclosed quote on line {number}")
            value = value[1:-1]
        result[key] = value
    return result


def configured_value(name: str, file_vars: dict[str, str]) -> str:
    """Explicit environment takes precedence; useful for CI with injected secrets."""
    return os.environ.get(name, file_vars.get(name, "")).strip()


def normalize_base_url(raw: str) -> str:
    if not raw:
        raise KimaiConfigError("Set KIMAI_BASE_URL in .env")
    if any(ch.isspace() for ch in raw):
        raise KimaiConfigError("KIMAI_BASE_URL may not contain whitespace")
    url = urllib.parse.urlsplit(raw.rstrip("/"))
    if not url.hostname or url.username or url.password or url.query or url.fragment:
        raise KimaiConfigError("KIMAI_BASE_URL must be a plain Kimai base address, without credentials or query")
    is_loopback = url.hostname.lower() in {"localhost", "127.0.0.1", "::1"}
    if url.scheme != "https" and not (url.scheme == "http" and is_loopback):
        raise KimaiConfigError("Kimai API token requires HTTPS (HTTP is allowed only for loopback development)")
    if url.path.rstrip("/").endswith("/api"):
        raise KimaiConfigError("KIMAI_BASE_URL must omit the trailing /api")
    try:
        _ = url.port
    except ValueError:
        raise KimaiConfigError("Invalid URL port") from None
    return raw.rstrip("/")


class KimaiClient:
    def __init__(self, base_url: str, token: str = "", timeout: float = 10):
        self.base_url = normalize_base_url(base_url)
        self.token = token.strip()
        self.timeout = timeout
        self.session = requests.Session()
        self.session.trust_env = False

    def get(self, endpoint: str, params: dict[str, int | str] | None = None):
        """Only allow documented, read-only endpoints. Never follow redirects."""
        if endpoint not in {"/ping", "/version", "/users/me", "/customers", "/projects", "/activities", "/timesheets"}:
            raise ValueError("Unsupported endpoint")
        if endpoint == "/timesheets":
            # Never query timesheets across users or without a bounded date interval.
            if (not params or "user" not in params or "begin" not in params or "end" not in params
                    or not isinstance(params["user"], int) or params["user"] < 1
                    or not all(isinstance(params[k], str) and len(params[k]) == 19
                               and re.fullmatch(r"[0-9T:-]+", params[k])
                               for k in ("begin", "end"))):
                raise ValueError("Timesheet GET requires a user id and bounded date interval")
        query = "?" + urllib.parse.urlencode(params) if params else ""
        url = f"{self.base_url}/api{endpoint}{query}"
        headers = {"Accept": "application/json", "User-Agent": "automatic-kimai/0.1"}
        if self.token:
            headers["Authorization"] = f"Bearer {self.token}"
        try:
            response = self.session.get(url, headers=headers, timeout=(2.5, self.timeout),
                                        allow_redirects=False)
        except requests.RequestException as exc:
            raise KimaiAPIError(f"Connection/TLS failure on {endpoint}: {type(exc).__name__}") from None
        status = response.status_code
        if status in (301, 302, 303, 307, 308):
            raise KimaiAPIError(f"HTTP redirect {status} refused for credentials safety")
        if status == 401:
            raise KimaiAPIError("HTTP 401: token absent, expired, invalid, or unsupported by the server")
        if status == 403:
            raise KimaiAPIError(f"HTTP 403 on {endpoint}: token account lacks permission")
        if status != 200:
            raise KimaiAPIError(f"HTTP {status} on {endpoint}")
        if len(response.content) > 8 * 1024 * 1024:
            raise KimaiAPIError(f"Oversize JSON response from {endpoint}")
        try:
            return response.json(), dict(response.headers)
        except ValueError:
            raise KimaiAPIError(f"Non-JSON or invalid JSON response from {endpoint}") from None

    def list_entities(self, endpoint: str, *, limit_pages: int = 200):
        if endpoint not in {"/customers", "/projects", "/activities"}:
            raise ValueError("Only catalog endpoints are allowed")
        result = []
        warnings = []
        page = 1
        while page <= limit_pages:
            data, headers = self.get(endpoint, {"page": page, "size": 500})
            if isinstance(data, list):
                rows = data
            elif isinstance(data, dict) and isinstance(data.get("data"), list):
                rows = data["data"]
            else:
                raise KimaiAPIError(f"Unexpected catalog shape on {endpoint}")
            result.extend(rows)
            normalized_headers = {k.lower(): v for k, v in headers.items()}
            try:
                total_pages = int(normalized_headers["x-total-pages"])
            except (KeyError, TypeError, ValueError):
                total_pages = None
            if total_pages is None:
                if len(rows) >= 500:
                    warnings.append(
                        f"{endpoint}: response has >=500 items but no pagination headers; verify catalog completeness"
                    )
                break
            if page >= total_pages:
                break
            if not rows:
                warnings.append(f"{endpoint}: empty page before advertised end")
                break
            page += 1
        if page >= limit_pages and total_pages and page < total_pages:
            raise KimaiAPIError(f"Catalog pagination limit reached on {endpoint}")
        ids = [entry.get("id") for entry in result if isinstance(entry, dict)]
        if len(ids) != len(set(ids)):
            raise KimaiAPIError(f"Duplicate entity IDs returned on {endpoint}")
        return result, warnings


def _linked_id(data):
    if isinstance(data, dict):
        return data.get("id")
    return data if isinstance(data, int) else None


def minimal_catalog(client: KimaiClient):
    """Persist only fields needed for later project/activity matching."""
    customers, customer_warnings = client.list_entities("/customers")
    projects, project_warnings = client.list_entities("/projects")
    activities, activity_warnings = client.list_entities("/activities")
    output = {
        "fetched_at": datetime.now(timezone.utc).isoformat(),
        "source": "Kimai REST (read-only)",
        "customers": [
            {"id": obj.get("id"), "name": obj.get("name"), "visible": obj.get("visible")}
            for obj in customers
        ],
        "projects": [
            {"id": obj.get("id"), "name": obj.get("name"), "customer_id": _linked_id(obj.get("customer")),
             "visible": obj.get("visible")}
            for obj in projects
        ],
        "activities": [
            {"id": obj.get("id"), "name": obj.get("name"), "project_id": _linked_id(obj.get("project")),
             "visible": obj.get("visible")}
            for obj in activities
        ],
    }
    return output, customer_warnings + project_warnings + activity_warnings


def save_private_json(path: Path, payload: dict):
    path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    if path.parent.is_symlink() or path.is_symlink():
        raise KimaiConfigError("Refusing to write catalog through symbolic links")
    temp = path.with_name(path.name + ".tmp")
    flags = os.O_CREAT | os.O_TRUNC | os.O_WRONLY
    fd = os.open(temp, flags, 0o600)
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as file:
            json.dump(payload, file, ensure_ascii=False, indent=2)
            file.write("\n")
            file.flush()
            os.fsync(file.fileno())
        os.chmod(temp, 0o600)
        os.replace(temp, path)
    finally:
        if temp.exists():
            temp.unlink()
