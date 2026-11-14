# P-14-17 — Hostinger snapshot verify-only deployment gate

**Global ID:** P-14-17 · **Cursor ledger ID:** RadiumDesk-P-14-17

## Components

| Path | Role |
|------|------|
| `tools/lib/hostinger_vps_snapshot_gate.py` | Policy + reuse of `hostinger_vps_preflight` (GET only) |
| `tools/lib/hostinger-snapshot-gate.sh` | `deploy-kvm.sh` integration |
| `tools/commands/hostinger-vps-snapshot-gate.sh` | CLI |
| `tests/scripts/hostinger_vps_snapshot_gate_unittest.py` | Policy unit tests |
| `tests/scripts/hostinger-snapshot-gate.test.sh` | Static + shell smoke |

## Environment

| Variable | Purpose |
|----------|---------|
| `HOSTINGER_SNAPSHOT_GATE` | `1` enables Tier A/B enforcement on canonical KVM production target |
| `DEPLOY_TIER` | `A`, `B`, or `C` (required when gate enabled) |
| `HOSTINGER_SNAPSHOT_GATE_ID` | Optional expected snapshot id |
| `HOSTINGER_SNAPSHOT_EXPIRY_MARGIN_SECONDS` | Default `1800` — abort if expiry is sooner |
| `HOSTINGER_SNAPSHOT_GATE_PHASE` | Set by deploy script (`initial` / `final`) |

## Deploy order (P-14-23)

Live KVM deploy when the gate is enabled: **initial GET** → local confirm/build → **final GET** → `ensure_remote_deploy_ownership` (remote `chown`) → rsync safety → rsync.

Deploy lock: held from initial gate success until `deploy-kvm` exits (EXIT trap). Blocks concurrent gated deploys on the same operator host; **does not** prevent snapshot overwrite via Hostinger API from other clients.

## Owner rollout note

Default-off gate is **not** Tier A recovery protection. After validation, Owner may enable `HOSTINGER_SNAPSHOT_GATE=1` by default for Tier A only (future env default change requires explicit approval).