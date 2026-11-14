#!/usr/bin/env python3
"""
Hostinger VPS snapshot verify-only deployment gate (GET only).

Reuses hostinger_vps_preflight.HostingerVpsPreflight — no duplicate API client.
P-14-17 — fail-closed for Tier A/B when HOSTINGER_SNAPSHOT_GATE=1.
"""

from __future__ import annotations

import argparse
import json
import os
import sys
from dataclasses import dataclass
from datetime import datetime, timedelta, timezone
from typing import Any

import hostinger_vps_preflight as preflight


class SnapshotGateError(preflight.PreflightError):
    """Fail-closed snapshot gate failure."""


@dataclass(frozen=True)
class SnapshotGateConfig:
    gate_enabled: bool
    deploy_tier: str | None
    expected_snapshot_id: int | None = None
    expiry_safety_margin_seconds: int = 1800
    baseline_snapshot_id: int | None = None
    phase: str = "initial"


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


def _tier_normalized(tier: str | None) -> str | None:
    if tier is None or not str(tier).strip():
        return None
    return str(tier).strip().upper()


def evaluate_gate_report(
    preflight_data: dict[str, Any],
    config: SnapshotGateConfig,
    now: datetime | None = None,
) -> dict[str, Any]:
    """Evaluate gate policy on a successful preflight payload."""
    now = now or datetime.now(timezone.utc)
    tier = _tier_normalized(config.deploy_tier)
    report: dict[str, Any] = {
        "phase": config.phase,
        "gate_enabled": config.gate_enabled,
        "deploy_tier": tier,
        "checked_at": now.isoformat(),
        "preflight_ok": bool(preflight_data.get("ok")),
    }

    if not config.gate_enabled:
        report["ok"] = True
        report["verification_status"] = "not-required"
        report["reason"] = "hostinger_snapshot_gate_disabled"
        report["production_protected"] = False
        if tier in ("A", "B"):
            report["warning"] = (
                "Tier {0} deploy is not protected by Hostinger snapshot gate "
                "(HOSTINGER_SNAPSHOT_GATE is not 1).".format(tier)
            )
        return report

    if tier == "C":
        report["ok"] = True
        report["verification_status"] = "not-required"
        report["reason"] = "tier_c_exempt"
        report["production_protected"] = False
        return report

    if tier not in ("A", "B"):
        raise SnapshotGateError(
            "DEPLOY_TIER must be A, B, or C when HOSTINGER_SNAPSHOT_GATE=1 "
            f"(got: {config.deploy_tier!r})"
        )

    vm = preflight_data.get("vm") or {}
    snap = preflight_data.get("snapshot") or {}
    report["vm"] = {
        "id": vm.get("id"),
        "hostname": vm.get("hostname"),
        "state": vm.get("state"),
        "actions_lock": vm.get("actions_lock"),
    }
    report["snapshot"] = {
        "id": snap.get("id"),
        "created_at": snap.get("created_at"),
        "expires_at": snap.get("expires_at"),
        "present": snap.get("present"),
    }

    if vm.get("state") != "running":
        report["ok"] = False
        report["verification_status"] = "failed"
        report["abort_reason"] = f"vps_not_running: state={vm.get('state')!r}"
        return report

    lock = vm.get("actions_lock")
    if lock != "unlocked":
        report["ok"] = False
        report["verification_status"] = "failed"
        report["abort_reason"] = f"actions_lock_not_unlocked: {lock!r}"
        return report

    if snap.get("present") is not True:
        report["ok"] = False
        report["verification_status"] = "missing"
        report["abort_reason"] = "snapshot_absent"
        return report

    snap_id = snap.get("id")
    if snap_id is None:
        report["ok"] = False
        report["verification_status"] = "failed"
        report["abort_reason"] = "snapshot_id_missing_in_response"
        return report

    expires_at = _parse_utc(snap.get("expires_at"))
    if expires_at is None:
        report["ok"] = False
        report["verification_status"] = "failed"
        report["abort_reason"] = "snapshot_expires_at_unparseable"
        return report

    deadline = now + timedelta(seconds=config.expiry_safety_margin_seconds)
    if expires_at <= now:
        report["ok"] = False
        report["verification_status"] = "expired"
        report["abort_reason"] = "snapshot_already_expired"
        return report

    if expires_at <= deadline:
        report["ok"] = False
        report["verification_status"] = "expired"
        report["abort_reason"] = (
            f"snapshot_expires_before_deploy_window "
            f"(expires_at={expires_at.isoformat()}, margin_s={config.expiry_safety_margin_seconds})"
        )
        return report

    if config.expected_snapshot_id is not None and int(snap_id) != int(config.expected_snapshot_id):
        report["ok"] = False
        report["verification_status"] = "mismatch"
        report["abort_reason"] = (
            f"snapshot_id_mismatch: expected={config.expected_snapshot_id}, got={snap_id}"
        )
        return report

    if config.baseline_snapshot_id is not None and int(snap_id) != int(config.baseline_snapshot_id):
        report["ok"] = False
        report["verification_status"] = "mismatch"
        report["abort_reason"] = (
            f"snapshot_id_changed_since_initial_gate: "
            f"baseline={config.baseline_snapshot_id}, current={snap_id}"
        )
        return report

    report["ok"] = True
    report["verification_status"] = "verified"
    report["reason"] = "snapshot_metadata_accepted"
    report["production_protected"] = True
    report["expires_at_utc"] = expires_at.isoformat()
    report["snapshot_id"] = snap_id
    return report


def run_snapshot_gate(
    config: SnapshotGateConfig,
    token: str | None = None,
    runner: preflight.HostingerVpsPreflight | None = None,
) -> dict[str, Any]:
    tier = _tier_normalized(config.deploy_tier)
    if not config.gate_enabled:
        return evaluate_gate_report({"ok": True}, config)
    if tier == "C":
        return evaluate_gate_report({"ok": True}, config)
    if tier not in ("A", "B"):
        raise SnapshotGateError(
            "DEPLOY_TIER must be A, B, or C when HOSTINGER_SNAPSHOT_GATE=1 "
            f"(got: {config.deploy_tier!r})"
        )

    pre_cfg = preflight.PreflightConfig()
    runner = runner or preflight.HostingerVpsPreflight(pre_cfg)
    token = token or preflight.read_token(pre_cfg.token_file)
    preflight_data = runner.run(token)
    gate_report = evaluate_gate_report(preflight_data, config)
    gate_report["preflight"] = {
        "checked_at": preflight_data.get("checked_at"),
        "endpoints": preflight_data.get("endpoints"),
    }
    return gate_report


def config_from_env() -> SnapshotGateConfig:
    gate = os.environ.get("HOSTINGER_SNAPSHOT_GATE", "0").strip() in ("1", "true", "yes")
    tier = os.environ.get("DEPLOY_TIER")
    expected_raw = os.environ.get("HOSTINGER_SNAPSHOT_GATE_ID", "").strip()
    expected_id = int(expected_raw) if expected_raw.isdigit() else None
    baseline_raw = os.environ.get("HOSTINGER_SNAPSHOT_GATE_BASELINE_ID", "").strip()
    baseline_id = int(baseline_raw) if baseline_raw.isdigit() else None
    phase = os.environ.get("HOSTINGER_SNAPSHOT_GATE_PHASE", "initial").strip().lower()
    margin_raw = os.environ.get("HOSTINGER_SNAPSHOT_EXPIRY_MARGIN_SECONDS", "1800").strip()
    try:
        margin = int(margin_raw)
    except ValueError:
        margin = 1800
    return SnapshotGateConfig(
        gate_enabled=gate,
        deploy_tier=tier,
        expected_snapshot_id=expected_id,
        expiry_safety_margin_seconds=max(0, margin),
        baseline_snapshot_id=baseline_id,
        phase=phase if phase in ("initial", "final") else "initial",
    )


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Hostinger snapshot verify-only deploy gate (GET).")
    parser.add_argument("--json", action="store_true", help="Print machine-readable JSON report")
    parser.add_argument("--phase", choices=("initial", "final"), default=None)
    parser.add_argument("--baseline-snapshot-id", type=int, default=None)
    parser.add_argument("--deploy-tier", default=None)
    parser.add_argument("--gate-enabled", choices=("0", "1"), default=None)
    parser.add_argument("--expected-snapshot-id", type=int, default=None)
    parser.add_argument(
        "--expiry-margin-seconds",
        type=int,
        default=None,
        help="Abort if snapshot expires within this many seconds (default 1800)",
    )
    args = parser.parse_args(argv)

    cfg = config_from_env()
    if args.phase:
        cfg = SnapshotGateConfig(
            gate_enabled=cfg.gate_enabled if args.gate_enabled is None else args.gate_enabled == "1",
            deploy_tier=args.deploy_tier if args.deploy_tier is not None else cfg.deploy_tier,
            expected_snapshot_id=(
                args.expected_snapshot_id
                if args.expected_snapshot_id is not None
                else cfg.expected_snapshot_id
            ),
            expiry_safety_margin_seconds=(
                args.expiry_margin_seconds
                if args.expiry_margin_seconds is not None
                else cfg.expiry_safety_margin_seconds
            ),
            baseline_snapshot_id=(
                args.baseline_snapshot_id
                if args.baseline_snapshot_id is not None
                else cfg.baseline_snapshot_id
            ),
            phase=args.phase,
        )
    elif args.gate_enabled is not None:
        cfg = SnapshotGateConfig(
            gate_enabled=args.gate_enabled == "1",
            deploy_tier=args.deploy_tier if args.deploy_tier is not None else cfg.deploy_tier,
            expected_snapshot_id=cfg.expected_snapshot_id,
            expiry_safety_margin_seconds=cfg.expiry_safety_margin_seconds,
            baseline_snapshot_id=args.baseline_snapshot_id,
            phase=cfg.phase,
        )

    token_for_redaction: str | None = None
    try:
        tier = _tier_normalized(cfg.deploy_tier)
        needs_live_api = cfg.gate_enabled and tier in ("A", "B")
        if needs_live_api:
            token_for_redaction = preflight.read_token(preflight.PreflightConfig().token_file)
        report = run_snapshot_gate(cfg, token=token_for_redaction)
    except (SnapshotGateError, preflight.PreflightError) as e:
        msg = preflight._redact_secrets(str(e), token_for_redaction)
        err = {"ok": False, "verification_status": "failed", "abort_reason": msg}
        if args.json:
            print(json.dumps(err, indent=2))
        else:
            print(f"SNAPSHOT GATE FAILED: {msg}", file=sys.stderr)
        return 1
    except Exception as e:  # pragma: no cover
        msg = preflight._redact_secrets(str(e), token_for_redaction)
        print(f"SNAPSHOT GATE ERROR: {msg}", file=sys.stderr)
        return 2

    text = json.dumps(report, indent=2) if args.json else _format_human(report)
    print(preflight._redact_secrets(text, token_for_redaction))
    return 0 if report.get("ok") else 1


def _format_human(report: dict[str, Any]) -> str:
    lines = [
        "Hostinger snapshot gate (verify-only)",
        f"  verification_status: {report.get('verification_status')}",
        f"  deploy_tier: {report.get('deploy_tier')}",
        f"  gate_enabled: {report.get('gate_enabled')}",
        f"  phase: {report.get('phase')}",
        f"  production_protected: {report.get('production_protected')}",
    ]
    if report.get("warning"):
        lines.append(f"  warning: {report['warning']}")
    if report.get("abort_reason"):
        lines.append(f"  abort_reason: {report['abort_reason']}")
    if report.get("snapshot_id") is not None:
        snap = report.get("snapshot") or {}
        lines.append(
            f"  snapshot: id={report.get('snapshot_id')} expires={snap.get('expires_at')}"
        )
    return "\n".join(lines)


if __name__ == "__main__":
    sys.exit(main())