#!/usr/bin/env python3
"""Regression tests for build_rip_hostinger_block (P-14-26)."""

from __future__ import annotations

import json
import os
import subprocess
import sys
import unittest

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
sys.path.insert(0, os.path.join(ROOT, "tools", "lib"))

import build_rip_hostinger_block as rip_builder  # noqa: E402

FIXTURE = os.path.join(ROOT, "tests", "fixtures", "hostinger_snapshot_gate_verified.json")
SCRIPT = os.path.join(ROOT, "tools", "lib", "build_rip_hostinger_block.py")


class BuildRipHostingerBlockTests(unittest.TestCase):
    def setUp(self) -> None:
        with open(FIXTURE, encoding="utf-8") as f:
            self.gate = json.load(f)

    def test_build_omits_authorization_reference_from_manifest_block(self):
        block = rip_builder.build_block(
            self.gate,
            "7441de0d65c110de9f8c6dd11fdb419c67931a9c",
        )
        self.assertNotIn("authorization_reference", block)
        self.assertNotIn("api_token", block)
        self.assertNotIn("bearer", block)
        self.assertEqual(block["verification_status"], "verified")
        self.assertEqual(block["snapshot_id"], 455368)
        self.assertEqual(block["source_commit"], "7441de0")

    def test_cli_json_excludes_forbidden_keys_and_hints_ledger(self):
        result = subprocess.run(
            [
                sys.executable,
                SCRIPT,
                FIXTURE,
                "--source-commit",
                "7441de0d65c110de9f8c6dd11fdb419c67931a9c",
                "--authorization-reference",
                "RadiumDesk-P-14-18",
            ],
            capture_output=True,
            text=True,
            check=False,
        )
        self.assertEqual(result.returncode, 0, msg=result.stderr)
        block = json.loads(result.stdout)
        self.assertNotIn("authorization_reference", block)
        self.assertIn("cursor-prompt-ledger", result.stderr.lower())


if __name__ == "__main__":
    unittest.main()