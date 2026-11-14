#!/usr/bin/env python3
"""Unit tests for tools/lib/hostinger_vps_preflight.py (P-14-09)."""

from __future__ import annotations

import io
import json
import os
import sys
import tempfile
import unittest
import urllib.error
from unittest import mock

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
sys.path.insert(0, os.path.join(ROOT, "tools", "lib"))

import hostinger_vps_preflight as mod  # noqa: E402


def _fake_response(status: int, body: bytes, headers: dict | None = None):
    class Resp:
        def __init__(self):
            self.status = status
            self._body = body
            self.headers = headers or {}

        def read(self):
            return self._body

        def __enter__(self):
            return self

        def __exit__(self, *a):
            return False

    return Resp()


class HostingerVpsPreflightTests(unittest.TestCase):
    def setUp(self):
        self.cfg = mod.PreflightConfig(api_base="https://api.test")

    def test_hostname_match_and_mismatch(self):
        self.assertTrue(mod.hostname_matches("srv1910783.hstgr.cloud", "srv1910783.hstgr.cloud"))
        self.assertTrue(mod.hostname_matches("srv1910783.hstgr.cloud", "srv1910783.other"))
        self.assertFalse(mod.hostname_matches("srv1910783.hstgr.cloud", "srv9999999.hstgr.cloud"))
        self.assertFalse(mod.hostname_matches("srv1910783.hstgr.cloud", None))

    def test_missing_token(self):
        with self.assertRaises(mod.PreflightError) as ctx:
            mod.read_token("/nonexistent/token/path")
        self.assertIn("missing", str(ctx.exception))

    def test_unreadable_token_permissions(self):
        with tempfile.TemporaryDirectory() as td:
            path = os.path.join(td, "token")
            with open(path, "w", encoding="utf-8") as f:
                f.write("secret-value")
            os.chmod(path, 0o644)
            with self.assertRaises(mod.PreflightError) as ctx:
                mod.read_token(path)
            self.assertIn("permissions", str(ctx.exception))

    def test_empty_token(self):
        with tempfile.NamedTemporaryFile(mode="w", delete=False) as f:
            f.write("   ")
            path = f.name
        try:
            os.chmod(path, 0o600)
            with self.assertRaises(mod.PreflightError):
                mod.read_token(path)
        finally:
            os.unlink(path)

    def _run_with_responses(self, responses: list[tuple[int, object]]):
        calls: list[str] = []

        def fake_open(req, timeout=0):
            calls.append(req.full_url)
            status, payload = responses.pop(0)
            body = json.dumps(payload).encode() if payload is not None else b""
            return _fake_response(
                status,
                body,
                {"X-RateLimit-Remaining": "10", "X-RateLimit-Limit": "90"},
            )

        runner = mod.HostingerVpsPreflight(self.cfg, opener=fake_open)
        return runner.run("test-token-not-real"), calls

    def test_success_identity_and_parsing(self):
        vm_list = [{"id": 1910783, "hostname": "srv1910783.hstgr.cloud"}]
        vm_detail = {
            "id": 1910783,
            "hostname": "srv1910783.hstgr.cloud",
            "plan": "KVM 8",
            "state": "running",
            "actions_lock": "unlocked",
            "ipv4": [{"address": "187.127.129.16"}],
        }
        snapshot = {
            "id": 1,
            "created_at": "2026-11-14T03:40:37Z",
            "expires_at": "2026-11-15T03:40:37Z",
            "restore_time": 1800,
        }
        backups = {
            "data": [{"id": 9, "created_at": "2026-11-13T14:19:38Z", "size": 1, "restore_time": 1, "location": "x"}],
            "meta": {"total": 1},
        }
        data, _calls = self._run_with_responses(
            [(200, vm_list), (200, vm_detail), (200, snapshot), (200, backups)]
        )
        self.assertTrue(data["ok"])
        self.assertEqual(data["vm"]["plan"], "KVM 8")
        self.assertTrue(data["snapshot"]["present"])
        self.assertEqual(data["backups"]["count"], 1)

    def test_hostname_mismatch_fail_closed(self):
        vm_list = [{"id": 1910783, "hostname": "srv9999999.hstgr.cloud"}]
        with self.assertRaises(mod.PreflightError) as ctx:
            self._run_with_responses([(200, vm_list)])
        self.assertIn("hostname mismatch", str(ctx.exception))

    def test_vm_id_missing_from_list(self):
        with self.assertRaises(mod.PreflightError):
            self._run_with_responses([(200, [{"id": 1, "hostname": "srv1910783.hstgr.cloud"}])])

    def test_http_403_on_list(self):
        with self.assertRaises(mod.PreflightError) as ctx:
            self._run_with_responses([(403, {"error_name": "browser_signature_banned"})])
        self.assertIn("403", str(ctx.exception))

    def test_rate_limit_429(self):
        def fake_open(req, timeout=0):
            raise urllib.error.HTTPError(
                req.full_url, 429, "Too Many Requests", {"Retry-After": "30"}, io.BytesIO(b"{}")
            )

        runner = mod.HostingerVpsPreflight(self.cfg, opener=fake_open)
        with self.assertRaises(mod.PreflightError) as ctx:
            runner.run("tok")
        self.assertIn("429", str(ctx.exception))

    def test_timeout(self):
        def fake_open(req, timeout=0):
            raise TimeoutError("timed out")

        runner = mod.HostingerVpsPreflight(self.cfg, opener=fake_open)
        with self.assertRaises(mod.PreflightError) as ctx:
            runner.run("tok")
        self.assertIn("timed out", str(ctx.exception))

    def test_malformed_json(self):
        def fake_open(req, timeout=0):
            return _fake_response(200, b"not-json")

        runner = mod.HostingerVpsPreflight(self.cfg, opener=fake_open)
        with self.assertRaises(mod.PreflightError) as ctx:
            runner.run("tok")
        self.assertIn("malformed JSON", str(ctx.exception))

    def test_snapshot_absent_404(self):
        vm_list = [{"id": 1910783, "hostname": "srv1910783.hstgr.cloud"}]
        vm_detail = {
            "id": 1910783,
            "hostname": "srv1910783.hstgr.cloud",
            "plan": "KVM 8",
            "state": "running",
            "actions_lock": "unlocked",
            "ipv4": [],
        }

        def fake_open(req, timeout=0):
            if req.full_url.endswith("/snapshot"):
                raise urllib.error.HTTPError(req.full_url, 404, "n/a", {}, io.BytesIO(b""))
            if req.full_url.endswith("/backups"):
                return _fake_response(200, b'{"data":[]}')
            if req.full_url.endswith("/virtual-machines"):
                return _fake_response(200, json.dumps(vm_list).encode())
            return _fake_response(200, json.dumps(vm_detail).encode())

        data = mod.HostingerVpsPreflight(self.cfg, opener=fake_open).run("tok")
        self.assertFalse(data["snapshot"]["present"])

    def test_backups_malformed(self):
        vm_list = [{"id": 1910783, "hostname": "srv1910783.hstgr.cloud"}]
        vm_detail = {
            "id": 1910783,
            "hostname": "srv1910783.hstgr.cloud",
            "plan": "KVM 8",
            "state": "running",
            "actions_lock": "unlocked",
        }
        snap = {"id": 1, "created_at": "x", "expires_at": "y", "restore_time": 1}

        def fake_open(req, timeout=0):
            if req.full_url.endswith("/backups"):
                return _fake_response(200, b"42")
            if req.full_url.endswith("/snapshot"):
                return _fake_response(200, json.dumps(snap).encode())
            if req.full_url.endswith("/virtual-machines"):
                return _fake_response(200, json.dumps(vm_list).encode())
            return _fake_response(200, json.dumps(vm_detail).encode())

        with self.assertRaises(mod.PreflightError):
            mod.HostingerVpsPreflight(self.cfg, opener=fake_open).run("tok")

    def test_secret_redaction(self):
        token = "super-secret-token-xyz"
        out = mod._redact_secrets(f"Bearer {token} failed", token)
        self.assertNotIn(token, out)
        self.assertIn("[REDACTED]", out)

    def test_main_never_prints_token(self):
        with tempfile.NamedTemporaryFile(mode="w", delete=False) as f:
            f.write("super-secret-token-xyz")
            path = f.name
        os.chmod(path, 0o600)
        try:
            with mock.patch.object(mod.HostingerVpsPreflight, "run") as mrun:
                mrun.return_value = {
                    "vm": {
                        "id": 1910783,
                        "hostname": "srv1910783.hstgr.cloud",
                        "plan": "KVM 8",
                        "state": "running",
                        "actions_lock": "unlocked",
                        "ipv4": "187.127.129.16",
                    },
                    "snapshot": {"present": False, "http_status": 404},
                    "backups": {"count": 0, "items": []},
                }
                buf = io.StringIO()
                with mock.patch("sys.stdout", buf):
                    code = mod.main(["--token-file", path])
                self.assertEqual(code, 0)
                joined = buf.getvalue()
                self.assertNotIn("super-secret-token-xyz", joined)
        finally:
            os.unlink(path)


if __name__ == "__main__":
    unittest.main()