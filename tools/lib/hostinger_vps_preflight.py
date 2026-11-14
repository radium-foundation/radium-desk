#!/usr/bin/env python3
"""
Read-only Hostinger VPS preflight (GET only).

P-14-09 — uses verified production VM identity from P-14-08.
Never logs or prints the API bearer token.
"""

from __future__ import annotations

import argparse
import json
import os
import sys
import urllib.error
import urllib.request
from dataclasses import dataclass
from datetime import datetime, timezone
from typing import Any

API_BASE_DEFAULT = "https://developers.hostinger.com"
USER_AGENT = "radium-desk-hostinger-vps-preflight/1.0 (+https://github.com/radium-foundation/radium-desk)"
TOKEN_FILE_DEFAULT = os.path.expanduser("~/.config/hostinger/token")
EXPECTED_VM_ID = 1910783
EXPECTED_HOSTNAME = "srv1910783.hstgr.cloud"
REQUEST_TIMEOUT_SECONDS = 60


@dataclass(frozen=True)
class PreflightConfig:
    api_base: str = API_BASE_DEFAULT
    token_file: str = TOKEN_FILE_DEFAULT
    vm_id: int = EXPECTED_VM_ID
    expected_hostname: str = EXPECTED_HOSTNAME
    timeout: int = REQUEST_TIMEOUT_SECONDS


class PreflightError(Exception):
  """Fail-closed preflight failure."""


def _redact_secrets(text: str, token: str | None) -> str:
    if not token:
        return text
    return text.replace(token, "[REDACTED]")


def read_token(path: str) -> str:
    if not os.path.isfile(path):
        raise PreflightError(f"token file missing: {path}")
    if not os.access(path, os.R_OK):
        raise PreflightError(f"token file not readable: {path}")
    st = os.stat(path)
    if st.st_mode & 0o077:
        raise PreflightError(f"token file permissions too open (expected 0600): {path}")
    with open(path, encoding="utf-8") as handle:
        raw = handle.read().strip()
    if not raw:
        raise PreflightError("token file is empty")
    return raw


def hostname_matches(expected: str, actual: str | None) -> bool:
    if not actual:
        return False
    if actual == expected:
        return True
    # Accept short hostname prefix match for srv1910783.*
    return actual.split(".")[0] == expected.split(".")[0]


def parse_snapshot(payload: dict[str, Any] | None, http_status: int) -> dict[str, Any]:
    if http_status == 404:
        return {"present": False, "http_status": 404}
    if not isinstance(payload, dict):
        return {"present": "unknown", "http_status": http_status}
    return {
        "present": True,
        "http_status": http_status,
        "id": payload.get("id"),
        "created_at": payload.get("created_at"),
        "expires_at": payload.get("expires_at"),
        "restore_time_seconds": payload.get("restore_time"),
    }


def parse_backups(payload: Any, http_status: int) -> dict[str, Any]:
    if http_status >= 400:
        return {"http_status": http_status, "count": 0, "items": []}
    items: list[Any]
    meta: Any = None
    if isinstance(payload, dict) and "data" in payload:
        items = payload.get("data") or []
        meta = payload.get("meta")
    elif isinstance(payload, list):
        items = payload
    else:
        raise PreflightError("malformed backups response")
    summaries = []
    for row in items:
        if isinstance(row, dict):
            summaries.append(
                {
                    "id": row.get("id"),
                    "created_at": row.get("created_at"),
                    "size_kb": row.get("size"),
                    "restore_time_seconds": row.get("restore_time"),
                    "location": row.get("location"),
                }
            )
    return {"http_status": http_status, "count": len(summaries), "items": summaries, "meta": meta}


class HostingerVpsPreflight:
    def __init__(self, config: PreflightConfig | None = None, opener=None):
        self.config = config or PreflightConfig()
        self._opener = opener

    def api_get(self, path: str, token: str) -> tuple[int, dict[str, str], Any]:
        url = self.config.api_base.rstrip("/") + path
        req = urllib.request.Request(url, method="GET")
        req.add_header("Authorization", f"Bearer {token}")
        req.add_header("Accept", "application/json")
        req.add_header("User-Agent", USER_AGENT)
        req.add_header("Content-Type", "application/json")
        open_fn = self._opener or urllib.request.urlopen
        try:
            with open_fn(req, timeout=self.config.timeout) as resp:
                body = resp.read().decode("utf-8")
                headers = {k: v for k, v in resp.headers.items()}
                status = resp.status
        except urllib.error.HTTPError as e:
            status = e.code
            body = e.read().decode("utf-8", errors="replace")
            headers = {k: v for k, v in e.headers.items()}
        except TimeoutError as e:
            raise PreflightError("request timed out") from e
        except urllib.error.URLError as e:
            raise PreflightError(f"network error: {e.reason}") from e

        if status == 429:
            retry = headers.get("Retry-After", headers.get("retry-after"))
            raise PreflightError(f"rate limited (429); retry_after={retry}")

        data: Any
        if body:
            try:
                data = json.loads(body)
            except json.JSONDecodeError as e:
                raise PreflightError("malformed JSON response") from e
        else:
            data = None
        return status, headers, data

    def run(self, token: str | None = None) -> dict[str, Any]:
        token = token or read_token(self.config.token_file)
        result: dict[str, Any] = {
            "checked_at": datetime.now(timezone.utc).isoformat(),
            "api_base": self.config.api_base,
            "expected_vm_id": self.config.vm_id,
            "expected_hostname": self.config.expected_hostname,
            "endpoints": {},
        }

        status, headers, vms = self.api_get("/api/vps/v1/virtual-machines", token)
        result["endpoints"]["list_vms"] = {
            "http_status": status,
            "rate_limit_remaining": headers.get("X-RateLimit-Remaining"),
            "rate_limit_limit": headers.get("X-RateLimit-Limit"),
        }
        if status != 200 or not isinstance(vms, list):
            raise PreflightError(f"list virtual machines failed (HTTP {status})")

        matched = next((v for v in vms if v.get("id") == self.config.vm_id), None)
        if matched is None:
            raise PreflightError(f"VM id {self.config.vm_id} not found in account list")
        actual_hostname = matched.get("hostname")
        if not hostname_matches(self.config.expected_hostname, actual_hostname):
            raise PreflightError(
                f"hostname mismatch: expected {self.config.expected_hostname}, got {actual_hostname}"
            )

        status_d, headers_d, detail = self.api_get(
            f"/api/vps/v1/virtual-machines/{self.config.vm_id}", token
        )
        result["endpoints"]["vm_details"] = {
            "http_status": status_d,
            "rate_limit_remaining": headers_d.get("X-RateLimit-Remaining"),
        }
        if status_d != 200 or not isinstance(detail, dict):
            raise PreflightError(f"vm details failed (HTTP {status_d})")
        if detail.get("id") != self.config.vm_id:
            raise PreflightError("vm details id mismatch")
        if not hostname_matches(self.config.expected_hostname, detail.get("hostname")):
            raise PreflightError("vm details hostname mismatch")

        ipv4 = detail.get("ipv4")
        ip = None
        if isinstance(ipv4, list) and ipv4 and isinstance(ipv4[0], dict):
            ip = ipv4[0].get("address")

        result["vm"] = {
            "id": detail.get("id"),
            "hostname": detail.get("hostname"),
            "plan": detail.get("plan"),
            "state": detail.get("state"),
            "actions_lock": detail.get("actions_lock"),
            "ipv4": ip,
        }

        status_s, headers_s, snap = self.api_get(
            f"/api/vps/v1/virtual-machines/{self.config.vm_id}/snapshot", token
        )
        result["endpoints"]["snapshot"] = {
            "http_status": status_s,
            "rate_limit_remaining": headers_s.get("X-RateLimit-Remaining"),
        }
        if status_s not in (200, 404):
            raise PreflightError(f"snapshot GET failed (HTTP {status_s})")
        result["snapshot"] = parse_snapshot(snap if isinstance(snap, dict) else None, status_s)

        status_b, headers_b, backups = self.api_get(
            f"/api/vps/v1/virtual-machines/{self.config.vm_id}/backups", token
        )
        result["endpoints"]["backups"] = {
            "http_status": status_b,
            "rate_limit_remaining": headers_b.get("X-RateLimit-Remaining"),
        }
        if status_b != 200:
            raise PreflightError(f"backups list failed (HTTP {status_b})")
        result["backups"] = parse_backups(backups, status_b)
        result["ok"] = True
        return result


def format_human_report(data: dict[str, Any]) -> str:
    lines = [
        "Hostinger VPS preflight (read-only)",
        f"  VM id: {data['vm']['id']}",
        f"  hostname: {data['vm']['hostname']}",
        f"  plan: {data['vm']['plan']}",
        f"  state: {data['vm']['state']}",
        f"  actions_lock: {data['vm']['actions_lock']}",
        f"  ipv4: {data['vm'].get('ipv4')}",
    ]
    snap = data.get("snapshot", {})
    if snap.get("present") is True:
        lines.append(
            f"  snapshot: id={snap.get('id')} created={snap.get('created_at')} expires={snap.get('expires_at')}"
        )
    else:
        lines.append(f"  snapshot: none (HTTP {snap.get('http_status', 'n/a')})")
    backups = data.get("backups", {})
    lines.append(f"  backups listed: {backups.get('count', 0)}")
    for item in (backups.get("items") or [])[:5]:
        lines.append(f"    - id={item.get('id')} created={item.get('created_at')}")
    return "\n".join(lines)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Read-only Hostinger VPS preflight (GET only).")
    parser.add_argument("--json", action="store_true", help="Print JSON report to stdout")
    parser.add_argument(
        "--token-file",
        default=TOKEN_FILE_DEFAULT,
        help="Path to bearer token file (default: ~/.config/hostinger/token)",
    )
    parser.add_argument(
        "--api-base",
        default=API_BASE_DEFAULT,
        help="API base URL (default: developers.hostinger.com)",
    )
    args = parser.parse_args(argv)

    config = PreflightConfig(api_base=args.api_base, token_file=os.path.expanduser(args.token_file))
    token_for_redaction: str | None = None
    try:
        token_for_redaction = read_token(config.token_file)
        data = HostingerVpsPreflight(config).run(token_for_redaction)
    except PreflightError as e:
        msg = _redact_secrets(str(e), token_for_redaction)
        print(f"PREFLIGHT FAILED: {msg}", file=sys.stderr)
        return 1
    except Exception as e:  # pragma: no cover
        msg = _redact_secrets(str(e), token_for_redaction)
        print(f"PREFLIGHT ERROR: {msg}", file=sys.stderr)
        return 2

    text = json.dumps(data, indent=2) if args.json else format_human_report(data)
    print(_redact_secrets(text, token_for_redaction))
    return 0


if __name__ == "__main__":
    sys.exit(main())