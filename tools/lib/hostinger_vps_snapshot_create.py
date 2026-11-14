#!/usr/bin/env python3
"""
Owner-authorized Hostinger snapshot creation workflow (P-14-18/19).

GET assessment always; POST only with explicit Owner ledger reference and --execute.
Never invoked from deploy-kvm.
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

import hostinger_vps_preflight as preflight


class SnapshotCreateError(preflight.PreflightError):
    pass


@dataclass(frozen=True)
class SnapshotCreateAuthorization:
    owner_ledger_id: str
    purpose: str
    allow_overwrite: bool
    authorization_reference: str

    @staticmethod
    def from_environment() -> SnapshotCreateAuthorization:
        ledger = os.environ.get("HOSTINGER_SNAPSHOT_CREATE_OWNER_LEDGER_ID", "").strip()
        purpose = os.environ.get("HOSTINGER_SNAPSHOT_CREATE_PURPOSE", "").strip()
        ref = os.environ.get("HOSTINGER_SNAPSHOT_CREATE_AUTH_REF", "").strip()
        overwrite = os.environ.get("HOSTINGER_SNAPSHOT_OVERWRITE_OWNER", "").strip() in (
            "1",
            "true",
            "yes",
        )
        if not ledger:
            raise SnapshotCreateError(
                "HOSTINGER_SNAPSHOT_CREATE_OWNER_LEDGER_ID is required for snapshot create"
            )
        if not purpose:
            raise SnapshotCreateError("HOSTINGER_SNAPSHOT_CREATE_PURPOSE is required")
        if len(purpose) < 8:
            raise SnapshotCreateError("HOSTINGER_SNAPSHOT_CREATE_PURPOSE too short")
        if not ref:
            ref = ledger
        return SnapshotCreateAuthorization(
            owner_ledger_id=ledger,
            purpose=purpose,
            allow_overwrite=overwrite,
            authorization_reference=ref,
        )


def _parse_utc(value: str | None) -> datetime | None:
    if not value:
        return None
    normalized = value.replace("Z", "+00:00")
    try:
        parsed = datetime.fromisoformat(normalized)
    except ValueError:
        return None
    if parsed.tzinfo is None:
        parsed = parsed.replace(tzinfo=timezone.utc)
    return parsed.astimezone(timezone.utc)


def assess_existing_snapshot(preflight_data: dict, now: datetime | None = None) -> dict[str, Any]:
    now = now or datetime.now(timezone.utc)
    snap = preflight_data.get("snapshot") or {}
    present = snap.get("present") is True
    expires = _parse_utc(snap.get("expires_at")) if present else None
    still_valid = bool(present and expires and expires > now)
    return {
        "present": present,
        "snapshot_id": snap.get("id") if present else None,
        "created_at": snap.get("created_at"),
        "expires_at": snap.get("expires_at"),
        "still_valid_recovery_point": still_valid,
    }


def plan_snapshot_create(
    auth: SnapshotCreateAuthorization,
    preflight_data: dict,
    now: datetime | None = None,
) -> dict[str, Any]:
    slot = assess_existing_snapshot(preflight_data, now=now)
    vm = preflight_data.get("vm") or {}
    plan: dict[str, Any] = {
        "mode": "dry-run",
        "authorization_reference": auth.authorization_reference,
        "owner_ledger_id": auth.owner_ledger_id,
        "purpose": auth.purpose,
        "vm_id": vm.get("id"),
        "hostname": vm.get("hostname"),
        "existing_snapshot": slot,
        "shared_vps_warning": (
            "Snapshot create/restore affects every site on KVM 1910783, not Desk alone."
        ),
        "would_post": False,
    }
    if slot["still_valid_recovery_point"] and not auth.allow_overwrite:
        plan["abort"] = True
        plan["abort_reason"] = (
            "valid_snapshot_exists_overwrite_not_authorized "
            f"(id={slot.get('snapshot_id')}, expires={slot.get('expires_at')})"
        )
        return plan
    if vm.get("actions_lock") != "unlocked":
        plan["abort"] = True
        plan["abort_reason"] = f"actions_lock={vm.get('actions_lock')!r}"
        return plan
    if vm.get("state") != "running":
        plan["abort"] = True
        plan["abort_reason"] = f"vps_state={vm.get('state')!r}"
        return plan
    plan["would_post"] = True
    plan["api_path"] = f"/api/vps/v1/virtual-machines/{vm.get('id')}/snapshot"
    return plan


def api_post_snapshot(
    runner: preflight.HostingerVpsPreflight,
    token: str,
    vm_id: int,
) -> tuple[int, dict[str, str], Any]:
    """POST create snapshot — P-14-19 only; separate from preflight GET client."""
    path = f"/api/vps/v1/virtual-machines/{vm_id}/snapshot"
    url = runner.config.api_base.rstrip("/") + path
    req = urllib.request.Request(url, method="POST", data=b"{}")
    req.add_header("Authorization", f"Bearer {token}")
    req.add_header("Accept", "application/json")
    req.add_header("User-Agent", preflight.USER_AGENT)
    req.add_header("Content-Type", "application/json")
    open_fn = runner._opener or urllib.request.urlopen
    try:
        with open_fn(req, timeout=runner.config.timeout) as resp:
            body = resp.read().decode("utf-8")
            headers = {k: v for k, v in resp.headers.items()}
            status = resp.status
    except urllib.error.HTTPError as e:
        status = e.code
        body = e.read().decode("utf-8", errors="replace")
        headers = {k: v for k, v in e.headers.items()}
    except TimeoutError as e:
        raise SnapshotCreateError("request timed out") from e
    except urllib.error.URLError as e:
        raise SnapshotCreateError(f"network error: {e.reason}") from e

    data: Any = None
    if body:
        try:
            data = json.loads(body)
        except json.JSONDecodeError as e:
            raise SnapshotCreateError("malformed JSON response") from e
    return status, headers, data


def execute_snapshot_create(
    auth: SnapshotCreateAuthorization,
    runner: preflight.HostingerVpsPreflight,
    token: str,
) -> dict[str, Any]:
    """Re-query slot, POST, then GET snapshot to confirm."""
    preflight_data = runner.run(token)
    plan = plan_snapshot_create(auth, preflight_data)
    if plan.get("abort"):
        raise SnapshotCreateError(str(plan.get("abort_reason")))
    vm_id = int(plan["vm_id"])
    status, _headers, action = api_post_snapshot(runner, token, vm_id)
    if status not in (200, 201, 202):
        raise SnapshotCreateError(f"snapshot POST failed (HTTP {status})")
    action_id = action.get("id") if isinstance(action, dict) else None
    verify = runner.run(token)
    snap = verify.get("snapshot") or {}
    return {
        "ok": True,
        "authorization_reference": auth.authorization_reference,
        "action_id": action_id,
        "post_http_status": status,
        "snapshot_id": snap.get("id"),
        "snapshot_created_at": snap.get("created_at"),
        "snapshot_expires_at": snap.get("expires_at"),
        "verification_status": "recovery-point-verified" if snap.get("present") else "failed",
    }


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        description="Owner-authorized Hostinger snapshot create (never called from deploy)."
    )
    parser.add_argument(
        "--execute",
        action="store_true",
        help="Perform POST after authorization (requires Owner ledger id env; high risk)",
    )
    parser.add_argument("--json", action="store_true")
    args = parser.parse_args(argv)
    dry_run = not args.execute

    token: str | None = None
    try:
        auth = SnapshotCreateAuthorization.from_environment()
        token = preflight.read_token(preflight.PreflightConfig().token_file)
        runner = preflight.HostingerVpsPreflight()
        preflight_data = runner.run(token)
        if dry_run:
            plan = plan_snapshot_create(auth, preflight_data)
            plan["mode"] = "dry-run"
            if args.json:
                print(json.dumps(plan, indent=2))
            else:
                print(_plan_human(plan))
            return 1 if plan.get("abort") else 0
        result = execute_snapshot_create(auth, runner, token)
        text = json.dumps(result, indent=2) if args.json else json.dumps(result, indent=2)
        print(preflight._redact_secrets(text, token))
        return 0 if result.get("ok") else 1
    except SnapshotCreateError as e:
        msg = preflight._redact_secrets(str(e), token)
        print(f"SNAPSHOT CREATE FAILED: {msg}", file=sys.stderr)
        return 1
    except Exception as e:  # pragma: no cover
        msg = preflight._redact_secrets(str(e), token)
        print(f"SNAPSHOT CREATE ERROR: {msg}", file=sys.stderr)
        return 2


def _plan_human(plan: dict) -> str:
    lines = [
        "Hostinger snapshot create plan",
        f"  authorization: {plan.get('authorization_reference')}",
        f"  purpose: {plan.get('purpose')}",
        f"  shared_vps_warning: {plan.get('shared_vps_warning')}",
    ]
    ex = plan.get("existing_snapshot") or {}
    if ex.get("present"):
        lines.append(
            f"  existing: id={ex.get('snapshot_id')} valid={ex.get('still_valid_recovery_point')}"
        )
    else:
        lines.append("  existing: none")
    if plan.get("abort"):
        lines.append(f"  ABORT: {plan.get('abort_reason')}")
    elif plan.get("would_post"):
        lines.append(f"  would POST: {plan.get('api_path')}")
    return "\n".join(lines)


if __name__ == "__main__":
    sys.exit(main())