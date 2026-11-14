#!/usr/bin/env python3
"""Unit tests for snapshot create authorization (P-14-18/19, mocked POST)."""

from __future__ import annotations

import os
import sys
import unittest
from datetime import datetime, timedelta, timezone
from unittest import mock

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
sys.path.insert(0, os.path.join(ROOT, "tools", "lib"))

import hostinger_vps_snapshot_create as create  # noqa: E402


def _preflight(expires_hours=12, lock="unlocked", state="running", snap_id=1):
    now = datetime.now(timezone.utc)
    exp = (now + timedelta(hours=expires_hours)).strftime("%Y-%m-%dT%H:%M:%SZ")
    return {
        "vm": {"id": 1910783, "hostname": "srv1910783.hstgr.cloud", "state": state, "actions_lock": lock},
        "snapshot": {"present": True, "id": snap_id, "created_at": "x", "expires_at": exp},
    }


class SnapshotCreateAuthTests(unittest.TestCase):
    def test_auth_missing_ledger(self):
        with mock.patch.dict(os.environ, {}, clear=True):
            with self.assertRaises(create.SnapshotCreateError):
                create.SnapshotCreateAuthorization.from_environment()

    def test_valid_snapshot_blocks_without_overwrite(self):
        auth = create.SnapshotCreateAuthorization(
            owner_ledger_id="RadiumDesk-P-test",
            purpose="pre-deploy maintenance",
            allow_overwrite=False,
            authorization_reference="ref-1",
        )
        plan = create.plan_snapshot_create(auth, _preflight())
        self.assertTrue(plan["abort"])
        self.assertFalse(plan["would_post"])

    def test_overwrite_allows_plan(self):
        auth = create.SnapshotCreateAuthorization(
            owner_ledger_id="RadiumDesk-P-test",
            purpose="pre-deploy maintenance",
            allow_overwrite=True,
            authorization_reference="ref-1",
        )
        plan = create.plan_snapshot_create(auth, _preflight())
        self.assertFalse(plan.get("abort"))
        self.assertTrue(plan["would_post"])

    def test_execute_mocked_post_and_verify(self):
        auth = create.SnapshotCreateAuthorization(
            owner_ledger_id="RadiumDesk-P-test",
            purpose="pre-deploy maintenance",
            allow_overwrite=True,
            authorization_reference="ref-1",
        )
        runner = mock.Mock()
        runner.config.api_base = "https://api.test"
        runner.config.timeout = 5
        runner._opener = None
        runner.run.side_effect = [
            {**_preflight(snap_id=None), "snapshot": {"present": False, "http_status": 404}},
            {
                **_preflight(),
                "snapshot": {"present": True, "id": 999, "created_at": "a", "expires_at": "b"},
            },
        ]
        with mock.patch.object(create, "api_post_snapshot", return_value=(200, {}, {"id": 42, "state": "sent"})):
            result = create.execute_snapshot_create(auth, runner, "tok")
        self.assertTrue(result["ok"])
        self.assertEqual(result["snapshot_id"], 999)


if __name__ == "__main__":
    unittest.main()