#!/usr/bin/env python3
"""Unit tests for snapshot create authorization (P-14-18/19/27, mocked POST/poll)."""

from __future__ import annotations

import os
import sys
import unittest
from datetime import datetime, timedelta, timezone
from unittest import mock

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
sys.path.insert(0, os.path.join(ROOT, "tools", "lib"))

import hostinger_vps_snapshot_create as create  # noqa: E402


def _preflight(
    expires_hours=12,
    lock="unlocked",
    state="running",
    snap_id=1,
    *,
    expires_at="__default__",
    omit_expires_key=False,
):
    now = datetime.now(timezone.utc)
    snap = {"present": True, "id": snap_id, "created_at": "x"}
    if not omit_expires_key:
        if expires_at == "__default__":
            snap["expires_at"] = (now + timedelta(hours=expires_hours)).strftime("%Y-%m-%dT%H:%M:%SZ")
        else:
            snap["expires_at"] = expires_at
    return {
        "vm": {"id": 1910783, "hostname": "srv1910783.hstgr.cloud", "state": state, "actions_lock": lock},
        "snapshot": snap,
    }


def _auth(overwrite=False):
    return create.SnapshotCreateAuthorization(
        owner_ledger_id="RadiumDesk-P-test",
        purpose="pre-deploy maintenance",
        allow_overwrite=overwrite,
        authorization_reference="ref-1",
    )


class SnapshotCreateAuthTests(unittest.TestCase):
    def test_auth_missing_ledger(self):
        with mock.patch.dict(os.environ, {}, clear=True):
            with self.assertRaises(create.SnapshotCreateError):
                create.SnapshotCreateAuthorization.from_environment()

    def test_valid_snapshot_blocks_without_overwrite(self):
        plan = create.plan_snapshot_create(_auth(), _preflight())
        self.assertTrue(plan["abort"])
        self.assertFalse(plan["would_post"])

    def test_overwrite_allows_plan(self):
        plan = create.plan_snapshot_create(_auth(overwrite=True), _preflight())
        self.assertFalse(plan.get("abort"))
        self.assertTrue(plan["would_post"])

    def test_expired_snapshot_allows_plan_without_overwrite(self):
        now = datetime.now(timezone.utc)
        past = (now - timedelta(hours=1)).strftime("%Y-%m-%dT%H:%M:%SZ")
        plan = create.plan_snapshot_create(_auth(), _preflight(expires_at=past))
        self.assertFalse(plan.get("abort"))
        self.assertTrue(plan["would_post"])

    def test_ambiguous_expiry_blocks_without_overwrite(self):
        plan = create.plan_snapshot_create(_auth(), _preflight(omit_expires_key=True))
        self.assertTrue(plan["abort"])
        self.assertIn("ambiguous_snapshot_expiry", plan["abort_reason"])

    def test_ambiguous_expiry_allows_with_owner_overwrite(self):
        pf = _preflight(expires_at="not-a-timestamp")
        slot = create.assess_existing_snapshot(pf)
        self.assertTrue(slot["ambiguous_expiry"])
        plan = create.plan_snapshot_create(_auth(overwrite=True), pf)
        self.assertFalse(plan.get("abort"))


class AssessExistingSnapshotTests(unittest.TestCase):
    def test_absent_slot(self):
        pf = {**_preflight(), "snapshot": {"present": False, "http_status": 404}}
        slot = create.assess_existing_snapshot(pf)
        self.assertEqual(slot["expiry_state"], "absent")
        self.assertFalse(slot["still_valid_recovery_point"])

    def test_malformed_expiry_is_ambiguous(self):
        slot = create.assess_existing_snapshot(_preflight(expires_at="bad"))
        self.assertEqual(slot["expiry_state"], "ambiguous")
        self.assertTrue(slot["ambiguous_expiry"])


class PollSnapshotActionTests(unittest.TestCase):
    def _runner(self, states: list[str]):
        runner = mock.Mock()
        responses = [
            (200, {"id": 1, "state": state, "name": "snapshot_create"})
            for state in states
        ]
        runner.api_get.side_effect = [
            (200, {}, body) for _status, body in responses
        ]
        return runner

    def test_poll_success_immediate(self):
        runner = self._runner(["success"])
        out = create.poll_snapshot_action(
            runner, "tok", 1910783, 99, interval_seconds=0, sleep_fn=lambda _: None
        )
        self.assertEqual(out["poll_outcome"], "success")

    def test_poll_pending_then_success(self):
        runner = self._runner(["sent", "delayed", "success"])
        out = create.poll_snapshot_action(
            runner, "tok", 1910783, 99, interval_seconds=0, sleep_fn=lambda _: None
        )
        self.assertEqual(out["state"], "success")
        self.assertEqual(runner.api_get.call_count, 3)

    def test_poll_error_terminal(self):
        runner = self._runner(["error"])
        out = create.poll_snapshot_action(
            runner, "tok", 1910783, 99, interval_seconds=0, sleep_fn=lambda _: None
        )
        self.assertEqual(out["poll_outcome"], "error")

    def test_poll_timeout(self):
        runner = self._runner(["sent"] * 5)
        with self.assertRaises(create.SnapshotCreateError) as ctx:
            create.poll_snapshot_action(
                runner,
                "tok",
                1910783,
                99,
                timeout_seconds=0,
                interval_seconds=0,
                sleep_fn=lambda _: None,
                monotonic_fn=lambda: 0.0,
            )
        self.assertIn("timeout", str(ctx.exception).lower())

    def test_poll_http_failure(self):
        runner = mock.Mock()
        runner.api_get.return_value = (500, {}, {"message": "fail"})
        with self.assertRaises(create.SnapshotCreateError):
            create.poll_snapshot_action(
                runner, "tok", 1910783, 99, interval_seconds=0, sleep_fn=lambda _: None
            )

    def test_poll_malformed_body(self):
        runner = mock.Mock()
        runner.api_get.return_value = (200, {}, None)
        with self.assertRaises(create.SnapshotCreateError):
            create.poll_snapshot_action(
                runner, "tok", 1910783, 99, interval_seconds=0, sleep_fn=lambda _: None
            )


class ExecuteSnapshotCreateTests(unittest.TestCase):
    def test_execute_polls_before_verify_no_duplicate_post(self):
        auth = _auth(overwrite=True)
        runner = mock.Mock()
        runner.config.api_base = "https://api.test"
        runner.config.timeout = 5
        runner._opener = None
        absent = {**_preflight(snap_id=None), "snapshot": {"present": False, "http_status": 404}}
        present = {
            **_preflight(snap_id=999),
            "snapshot": {"present": True, "id": 999, "created_at": "a", "expires_at": "b"},
        }
        runner.run.side_effect = [absent, present]
        runner.api_get.return_value = (200, {}, {"id": 42, "state": "success"})

        with mock.patch.object(create, "api_post_snapshot", return_value=(202, {}, {"id": 42, "state": "sent"})) as post:
            result = create.execute_snapshot_create(auth, runner, "tok")

        self.assertTrue(result["ok"])
        post.assert_called_once()
        runner.api_get.assert_called()
        self.assertEqual(result["snapshot_id"], 999)

    def test_execute_does_not_retry_post_when_snapshot_slow(self):
        auth = _auth(overwrite=True)
        runner = mock.Mock()
        runner.run.side_effect = [
            {**_preflight(snap_id=None), "snapshot": {"present": False, "http_status": 404}},
            {**_preflight(), "snapshot": {"present": False, "http_status": 404}},
        ]
        runner.api_get.return_value = (200, {}, {"id": 7, "state": "success"})

        with mock.patch.object(create, "api_post_snapshot", return_value=(200, {}, {"id": 7, "state": "sent"})) as post:
            with self.assertRaises(create.SnapshotCreateError):
                create.execute_snapshot_create(auth, runner, "tok")
        post.assert_called_once()

    def test_execute_fails_on_action_error_without_second_post(self):
        auth = _auth(overwrite=True)
        runner = mock.Mock()
        runner.run.return_value = {
            **_preflight(snap_id=None),
            "snapshot": {"present": False, "http_status": 404},
        }
        runner.api_get.return_value = (200, {}, {"id": 8, "state": "error"})

        with mock.patch.object(create, "api_post_snapshot", return_value=(200, {}, {"id": 8, "state": "sent"})) as post:
            with self.assertRaises(create.SnapshotCreateError) as ctx:
                create.execute_snapshot_create(auth, runner, "tok")
        post.assert_called_once()
        self.assertIn("action failed", str(ctx.exception).lower())


if __name__ == "__main__":
    unittest.main()