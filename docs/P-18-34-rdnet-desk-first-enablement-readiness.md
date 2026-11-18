# P-18-34 — Desk-first enablement readiness for RN357 / RN377 (rdservice.net)

**Global prompt ID:** P-18-34  
**Cursor ledger ID:** `RadiumDesk-P-18-34`  
**Risk:** HIGH — Central Wallet configuration and wallet refund execution  
**Companion spoke evidence:** rdservice.net merge `336610cc5eece7090d05727aa85f01400a46179b` (P-18-33)

## Current production state (read-only)

| Item | Value |
|------|--------|
| `CENTRAL_WALLET_DESK_FIRST_WALLET_REFUND_ENABLED` | **true** (runtime KVM8, read-only 2026-11-18) |
| `CENTRAL_WALLET_DESK_FIRST_WALLET_REFUND_SPOKES` | **`rdservice.in,radiumbox.com`** — **rdservice.net absent** |
| Code default if env unset | `rdservice.in,rdservice.net,radiumbox.com` — **not** production effective value |
| REF-67494 (RN357) | **pending_execution**, ₹599.00, not executed |
| REF-67516 (RN377) | **pending_execution**, ₹979.00, not executed |

With `desk_authoritative_enabled` default **true**, wallet completion **requires** desk-first flag before RN orders can execute (no legacy spoke poster for RDNET on merchant prod).

## Spoke contract compatibility (Desk → rdservice.net)

`RdServiceNetWalletRefundClient` POST body matches P-18-33 / `336610cc`:

- `source_system`, `desk_refund_reference`, `order_id` (RN*), `amount`, `currency`, `customer_email` (optional), `central_wallet_ledger_entry_id` (desk-first)

`DeskFirstWalletRefundOrchestrator` credits Desk ledger **first**, then audit POST — **no second spendable credit**.

## Recommended production activation (Owner gate — not performed in P-18-34)

1. **Backup:** Fresh Desk encrypted DB run + note latest backup id (P-18-32 audit); optional pre-change RIP on Desk if deploying code (this release is **config-only** if main already contains orchestrator).
2. **Deploy:** Merge this documentation/tests branch to `main` and deploy Desk when Owner approves (no code path change beyond tests/docs if already on prod).
3. **Config (single instance, reversible):**
   - **Append** `rdservice.net` to existing spokes:  
     `CENTRAL_WALLET_DESK_FIRST_WALLET_REFUND_SPOKES=rdservice.in,radiumbox.com,rdservice.net`  
     (matches current prod list + net; do **not** remove rbx unless Owner directs)
   - Leave `CENTRAL_WALLET_DESK_FIRST_WALLET_REFUND_ENABLED=true` unless Owner chooses staged cutover
   - `php artisan config:clear` (and `config:cache` if site uses cached config)
4. **Execute (separate Owner action):** Complete **REF-67494** then **REF-67516** via normal Desk refund UI (one at a time; verify spoke audit row + ledger id).

### Rollback

1. Set `CENTRAL_WALLET_DESK_FIRST_WALLET_REFUND_ENABLED=false`
2. Remove `rdservice.net` from spokes list (restore prior comma list)
3. `config:clear`  
4. **Do not** revoke completed ledger credits without finance runbook; pending refunds remain `pending_execution` if execution not started.

## Global impact when flag enabled

| Spoke in allowlist | Behaviour with `desk_first_enabled=true` |
|--------------------|------------------------------------------|
| **rdservice.in** | Desk-first + audit (existing production pattern) |
| **rdservice.net** | Desk-first + audit (after allowlist add) |
| **radiumbox.com** | **Unchanged** if not in allowlist — completion blocked for RB desk-first until listed |
| Non-allowlisted owners | Fail-closed validation (`DeskFirstWalletRefundSpokePolicy`) |

## Explicit prohibitions (P-18-34)

**NO** production `.env` changes, deploy, refund execution, or allowlist activation in this prompt.