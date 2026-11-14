# P-14-09 — Hostinger VPS read-only preflight implementation

**Global ID:** P-14-09 · **Cursor ledger ID:** RadiumDesk-P-14-09  
**Depends on:** prior P-14-08 verification (VM `1910783`; evidence in Cursor ledger, not required in this branch diff)

## Usage

```bash
./tools/desk hostinger-vps-preflight
./tools/desk hostinger-vps-preflight --json
```

Token: `~/.config/hostinger/token` (0600, off-repo). **GET only.**

## Components

| Path | Role |
|------|------|
| `tools/lib/hostinger_vps_preflight.py` | API client + fail-closed checks |
| `tools/commands/hostinger-vps-preflight.sh` | CLI wrapper |
| `tests/scripts/hostinger_vps_preflight_unittest.py` | Focused unit tests |
| `tests/scripts/hostinger-vps-preflight.test.sh` | Static guards + unittest runner |

Verified identity is pinned in code: VM **1910783**, hostname **`srv1910783.hstgr.cloud`**.

## Validation (2026-11-14)

- `bash tests/scripts/hostinger-vps-preflight.test.sh` — **15/15** unit tests **PASS**
- Live smoke `./tools/desk hostinger-vps-preflight` — **PASS** (~5s)