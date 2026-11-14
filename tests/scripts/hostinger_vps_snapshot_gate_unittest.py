#!/usr/bin/env python3
"""Unit tests for tools/lib/hostinger_vps_snapshot_gate.py (P-14-17)."""

from __future__ import annotations

import os
import sys
import unittest
from datetime import datetime, timedelta, timezone
from unittest import mock

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
sys.path.insert(0, os.path.join(ROOT, "tools", "lib"))

import hostinger_vps_preflight as preflight  # noqa: E402
import hostinger_vps_snapshot_gate as gate  # noqa: E402


def _vm_data(
    state="running",
    lock="unlocked",
    snap_present=True,
    snap_id=455368,
    expires_offset_hours=12,
):
    now = datetime.now(timezone.utc)
    expires = (now + timedelta(hours=expires_offset_hours)).strftime("%Y-%m-%dT%H:%M:%SZ")
    snap = (
        {
            "present": True,
            "id": snap_id,
            "created_at": "2026-11-14T03:40:37Z",
            "expires_at": expires,
        }
        if snap_present
        else {"present": False, "http_status": 404}
    )
    return {
        "ok": True,
        "vm": {
            "id": 1910783,
            "hostname": "srv1910783.hstgr.cloud",
            "state": state,
            "actions_lock": lock,
        },
        "snapshot": snap,
    }


class SnapshotGateEvaluateTests(unittest.TestCase):
    def test_gate_disabled_tier_a_warns(self):
        cfg = gate.SnapshotGateConfig(gate_enabled=False, deploy_tier="A")
        r = gate.evaluate_gate_report(_vm_data(), cfg)
        self.assertTrue(r["ok"])
        self.assertEqual(r["verification_status"], "not-required")
        self.assertIn("warning", r)
        self.assertFalse(r["production_protected"])

    def test_tier_c_exempt_when_gate_on(self):
        cfg = gate.SnapshotGateConfig(gate_enabled=True, deploy_tier="C")
        r = gate.evaluate_gate_report(_vm_data(), cfg)
        self.assertTrue(r["ok"])
        self.assertEqual(r["verification_status"], "not-required")

    def test_tier_a_verified(self):
        cfg = gate.SnapshotGateConfig(gate_enabled=True, deploy_tier="A")
        r = gate.evaluate_gate_report(_vm_data(), cfg)
        self.assertTrue(r["ok"])
        self.assertEqual(r["verification_status"], "verified")

    def test_missing_tier_raises(self):
        cfg = gate.SnapshotGateConfig(gate_enabled=True, deploy_tier=None)
        with self.assertRaises(gate.SnapshotGateError):
            gate.evaluate_gate_report(_vm_data(), cfg)

    def test_vm_not_running(self):
        cfg = gate.SnapshotGateConfig(gate_enabled=True, deploy_tier="A")
        r = gate.evaluate_gate_report(_vm_data(state="stopped"), cfg)
        self.assertFalse(r["ok"])
        self.assertEqual(r["verification_status"], "failed")

    def test_actions_locked(self):
        cfg = gate.SnapshotGateConfig(gate_enabled=True, deploy_tier="A")
        r = gate.evaluate_gate_report(_vm_data(lock="locked"), cfg)
        self.assertFalse(r["ok"])

    def test_snapshot_missing(self):
        cfg = gate.SnapshotGateConfig(gate_enabled=True, deploy_tier="A")
        r = gate.evaluate_gate_report(_vm_data(snap_present=False), cfg)
        self.assertFalse(r["ok"])
        self.assertEqual(r["verification_status"], "missing")

    def test_snapshot_expired(self):
        cfg = gate.SnapshotGateConfig(gate_enabled=True, deploy_tier="A")
        now = datetime.now(timezone.utc)
        data = _vm_data(expires_offset_hours=48)
        data["snapshot"]["expires_at"] = (now - timedelta(hours=1)).strftime("%Y-%m-%dT%H:%M:%SZ")
        r = gate.evaluate_gate_report(data, cfg, now=now)
        self.assertFalse(r["ok"])
        self.assertEqual(r["verification_status"], "expired")

    def test_snapshot_within_margin(self):
        cfg = gate.SnapshotGateConfig(
            gate_enabled=True, deploy_tier="A", expiry_safety_margin_seconds=3600
        )
        now = datetime.now(timezone.utc)
        data = _vm_data(expires_offset_hours=48)
        data["snapshot"]["expires_at"] = (now + timedelta(minutes=30)).strftime("%Y-%m-%dT%H:%M:%SZ")
        r = gate.evaluate_gate_report(data, cfg, now=now)
        self.assertFalse(r["ok"])
        self.assertEqual(r["verification_status"], "expired")

    def test_expected_snapshot_id_mismatch(self):
        cfg = gate.SnapshotGateConfig(
            gate_enabled=True, deploy_tier="B", expected_snapshot_id=1
        )
        r = gate.evaluate_gate_report(_vm_data(snap_id=455368), cfg)
        self.assertFalse(r["ok"])
        self.assertEqual(r["verification_status"], "mismatch")

    def test_baseline_id_changed_final_phase(self):
        cfg = gate.SnapshotGateConfig(
            gate_enabled=True,
            deploy_tier="A",
            baseline_snapshot_id=100,
            phase="final",
        )
        r = gate.evaluate_gate_report(_vm_data(snap_id=200), cfg)
        self.assertFalse(r["ok"])
        self.assertIn("changed_since_initial", r["abort_reason"])


class SnapshotGateRunTests(unittest.TestCase):
    def test_run_skips_api_when_gate_off(self):
        cfg = gate.SnapshotGateConfig(gate_enabled=False, deploy_tier="A")
        with mock.patch.object(preflight.HostingerVpsPreflight, "run") as mrun:
            r = gate.run_snapshot_gate(cfg, token="x")
            mrun.assert_not_called()
        self.assertTrue(r["ok"])

    def test_run_calls_preflight_when_tier_a(self):
        cfg = gate.SnapshotGateConfig(gate_enabled=True, deploy_tier="A")
        fake = mock.Mock()
        fake.run.return_value = _vm_data()
        r = gate.run_snapshot_gate(cfg, token="x", runner=fake)
        fake.run.assert_called_once()
        self.assertTrue(r["ok"])

    def test_preflight_error_propagates(self):
        cfg = gate.SnapshotGateConfig(gate_enabled=True, deploy_tier="A")
        fake = mock.Mock()
        fake.run.side_effect = preflight.PreflightError("403")
        with self.assertRaises(preflight.PreflightError):
            gate.run_snapshot_gate(cfg, token="x", runner=fake)


if __name__ == "__main__":
    unittest.main()