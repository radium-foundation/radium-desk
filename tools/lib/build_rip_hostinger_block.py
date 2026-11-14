#!/usr/bin/env python3
"""Build hostinger_vps_recovery JSON for RIP manifest from gate JSON (P-14-20)."""

from __future__ import annotations

import argparse
import json
import sys
from datetime import datetime, timezone


def build_block(gate: dict, source_commit: str) -> dict:
    """Build manifest-safe recovery metadata. Owner auth belongs in the ledger, not manifest.json."""
    status = gate.get("verification_status", "failed")
    if gate.get("ok") and status == "verified":
        rip_status = "verified"
    elif status == "missing":
        rip_status = "missing"
    elif status == "expired":
        rip_status = "expired"
    elif status == "mismatch":
        rip_status = "mismatch"
    elif status == "not-required":
        rip_status = "not-required"
    else:
        rip_status = "failed"

    snap = gate.get("snapshot") or {}
    return {
        "schema_version": 1,
        "provider": "hostinger",
        "virtual_machine_id": 1910783,
        "hostname": (gate.get("vm") or {}).get("hostname") or "srv1910783.hstgr.cloud",
        "snapshot_id": gate.get("snapshot_id") or snap.get("id"),
        "snapshot_created_at": snap.get("created_at"),
        "snapshot_expires_at": snap.get("expires_at"),
        "verified_at": gate.get("checked_at") or datetime.now(timezone.utc).isoformat(),
        "verification_status": rip_status,
        "source_commit": source_commit[:7] if source_commit else None,
        "deploy_tier": gate.get("deploy_tier"),
        "phase": gate.get("phase"),
    }


def _ledger_hint(authorization_reference: str | None) -> None:
    if not authorization_reference:
        return
    print(
        "Note: record Owner authorization in docs/cursor-prompt-ledger.md "
        f"(not in manifest.json): {authorization_reference}",
        file=sys.stderr,
    )


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("gate_json_file")
    parser.add_argument("--source-commit", required=True)
    parser.add_argument(
        "--authorization-reference",
        help="Owner ledger row id for create/verify auth; never written into manifest.json",
    )
    args = parser.parse_args()
    gate = json.load(open(args.gate_json_file, encoding="utf-8"))
    _ledger_hint(args.authorization_reference)
    block = build_block(gate, args.source_commit)
    print(json.dumps(block, indent=2))
    return 0


if __name__ == "__main__":
    sys.exit(main())