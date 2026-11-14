# P-14-18 — Hostinger snapshot authorization and lifecycle hardening

**Global ID:** P-14-18

## Commands (separate from deploy)

| Command | Mutates VPS |
|---------|-------------|
| `desk hostinger-vps-preflight` | No (GET) |
| `desk hostinger-vps-snapshot-status` | No (GET) |
| `desk hostinger-vps-snapshot-gate` | No (GET) |
| `desk hostinger-vps-snapshot-create` | Only with `--execute` + Owner env |

## Owner authorization (create)

Required environment (never in git):

- `HOSTINGER_SNAPSHOT_CREATE_OWNER_LEDGER_ID` — Cursor ledger row authorizing this create
- `HOSTINGER_SNAPSHOT_CREATE_PURPOSE` — human purpose (≥8 chars)
- `HOSTINGER_SNAPSHOT_CREATE_AUTH_REF` — optional correlation id (defaults to ledger id)
- `HOSTINGER_SNAPSHOT_OVERWRITE_OWNER=1` — required to replace a **still-valid** snapshot

Default invocation is **dry-run** (plan only). `--execute` performs POST (P-14-19); **no live POST** in P-14-18 validation.

## Not authorization

Merge, CI, deploy request, `HOSTINGER_SNAPSHOT_GATE`, API token, or an existing snapshot.

## Shared KVM

Snapshot create/restore is whole-VPS — affects Desk, Box, RDIN, RDNET, RadiumSign, and UAT on `1910783`.