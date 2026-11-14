#!/usr/bin/env python3
"""Read-only Hostinger snapshot slot status (GET via preflight client). P-14-18."""

from __future__ import annotations

import argparse
import json
import sys

import hostinger_vps_preflight as preflight


def snapshot_status_report(preflight_data: dict) -> dict:
    snap = preflight_data.get("snapshot") or {}
    vm = preflight_data.get("vm") or {}
    return {
        "vm_id": vm.get("id"),
        "hostname": vm.get("hostname"),
        "plan": vm.get("plan"),
        "state": vm.get("state"),
        "actions_lock": vm.get("actions_lock"),
        "snapshot_present": snap.get("present") is True,
        "snapshot_id": snap.get("id"),
        "snapshot_created_at": snap.get("created_at"),
        "snapshot_expires_at": snap.get("expires_at"),
        "restore_time_seconds": snap.get("restore_time_seconds"),
        "checked_at": preflight_data.get("checked_at"),
    }


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Hostinger VPS snapshot status (GET only).")
    parser.add_argument("--json", action="store_true")
    args = parser.parse_args(argv)
    token: str | None = None
    try:
        token = preflight.read_token(preflight.PreflightConfig().token_file)
        data = preflight.HostingerVpsPreflight().run(token)
        report = snapshot_status_report(data)
    except preflight.PreflightError as e:
        msg = preflight._redact_secrets(str(e), token)
        print(f"STATUS FAILED: {msg}", file=sys.stderr)
        return 1
    text = json.dumps(report, indent=2) if args.json else _human(report)
    print(preflight._redact_secrets(text, token))
    return 0


def _human(report: dict) -> str:
    lines = ["Hostinger snapshot status (read-only)"]
    if report.get("snapshot_present"):
        lines.append(
            f"  snapshot id={report.get('snapshot_id')} "
            f"created={report.get('snapshot_created_at')} "
            f"expires={report.get('snapshot_expires_at')}"
        )
    else:
        lines.append("  snapshot: none")
    lines.append(f"  vm: {report.get('vm_id')} {report.get('hostname')} lock={report.get('actions_lock')}")
    return "\n".join(lines)


if __name__ == "__main__":
    sys.exit(main())