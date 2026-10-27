# Central Wallet ownership policy (Desk authoritative)

**Prompt:** `RadiumDesk-P-04-10-203`  
**Status:** Architecture policy — enforcement is staged per integration.

## Business contract

1. **Desk** owns Central Wallet accounts, ledger, spendable balance, statements, wallet refunds, reversals, and reconciliation journals.
2. **Spokes** may record **non-spendable audit** rows when Desk supplies `central_wallet_ledger_entry_id`; they must not mint a second spendable credit for the same refund.
3. **Legacy spoke balances** remain spendable until retired through an approved **paired migration** (Lane A/B) or an equivalent audited process — never silent double-credit.
4. **Customer 360** and spoke UIs must read Desk-authoritative balance/statement via verified APIs (`wallet-visibility`, ledger read) with stale/unavailable handling.
5. **Email** alone does not authorize wallet disclosure or spending; server-side verification and canonical identity are required (`WalletVisibilityService`).

## Runtime enforcement (Desk)

| Mode | Refund path | CW ledger | Spoke credit |
|------|-------------|-----------|--------------|
| `CENTRAL_WALLET_DESK_FIRST_WALLET_REFUND_ENABLED=false` (default prod) | `WalletRefundExecutor` → spoke clients | Optional / absent for legacy | **Spendable** on spoke when integration enabled |
| `CENTRAL_WALLET_DESK_FIRST_WALLET_REFUND_ENABLED=true` | `DeskFirstWalletRefundOrchestrator` | **Required** first | Audit-only when spoke handler supports `central_wallet_ledger_entry_id` |

Desk-first allowlist defaults to **`rdservice.in` only** (`CENTRAL_WALLET_DESK_FIRST_WALLET_REFUND_SPOKES`).

## Historical reconciliation (not automatic)

- Inventory: `php artisan central-wallet:wallet-refund-discrepancy-report` (read-only JSON).
- Execute: gated pilot/batch migration (`central-wallet:pilot-refund-migration-*`) with manifest SHA, owner approval, and spoke retirement — **never** single-refund hard-coding in application code.
- Individual cases (e.g. REF-67395) are rows in the inventory, not special cases in code.

### Report semantics

| Field | Meaning |
|-------|---------|
| `classification=spoke_execution_without_desk_ledger` | Terminal wallet refund with spoke execution evidence and **no** posted Desk CW credit (and not `reconciled` migration). |
| `policy_violation=true` | Same row — **non-compliant with the target Desk-authoritative contract**. While `CENTRAL_WALLET_DESK_FIRST_WALLET_REFUND_ENABLED=false`, this is **expected legacy behavior**, not an operational defect by itself. Use the report to size migration cohorts and verify cutover per spoke. |
| `classification=migration_reconciled` | Covered by an approved `central_wallet_refund_migrations` row or equivalent reconciled filter — excluded from violation. |

### Production inventory snapshot (read-only, Desk prod 2026-10-13, v4.1.24)

| Metric | Count |
|--------|------:|
| Terminal wallet refunds (`closed` / `completed`) | 317 |
| Distinct refund refs with posted CW ledger credit | 88 |
| `policy_violation` rows (excl. reconciled migrations, excl. revoked) | **229** |
| `central_wallet_refund_migrations` status `reconciled` | 56 |

REF-67395 (`refund_id` 390): `spoke_execution_without_desk_ledger` — one row in the 229 cohort; remediation only via manifest + paired migration, not ad-hoc credits.

### RD17641 / REF-67395 diagnostic chain (prod verified 2026-10-13, Desk v4.1.25)

| Step | Evidence |
|------|----------|
| A. Canonical CWID | Order `RD17641` → `customer_id` `c51247d0-…` → `central_wallet_id` `d1852026-d8c2-4af4-818c-d3151549f614` (active). |
| B. Wallet link | Central customer row present and linked to CW wallet above. |
| C. Desk ledger | **0** posted entries on that wallet; **0** ledger rows for `business_reference=REF-67395`. |
| D. Balance API / C360 | `WalletLedgerReadService` uses `LedgerService::availableBalance` → **₹0.00** (correct for empty authoritative ledger). |
| E. UI | Customer 360 shows authoritative **₹0.00**; from v4.1.25+ (when deployed) shows **authority-gap notice** for closed wallet refunds without Desk credits — not a fabricated balance. |
| F. Auth / email | Separate from ₹0; wallet tab requires resolved Desk customer + permissions (`finance.wallet.view`). |
| G. Root cause | **Not** “missing UI balance” — **legacy spoke credit without Desk ledger credit** under desk-first-off mode. |
| H. RDIN spendable | `users_wallet` **#2667**: ₹597, `source_system=radium_desk`, `desk_refund_reference=REF-67395` (still spendable on spoke). |
| I. Duplicates / migration | No `central_wallet_refund_migrations` row for refund **390**; no Desk reversal detected in this read-only pass. |

Pilot manifest fixture: `tests/fixtures/cw-pilot-refund-ref-67395-manifest.json` (`manifest_rows_sha256` `052076aa…`).

## Legacy paths that mint or imply spendable wallet balance

| Location | Path | Spendable? | Retire when |
|----------|------|------------|-------------|
| **Desk** | `WalletRefundExecutor` → `RdServiceInWalletRefundClient` / `RadiumBoxWalletRefundClient` / `RdServiceNetWalletRefundClient` | Spoke-local when flag off | Desk-first enabled + spoke audit handler on prod |
| **Desk** | `DeskFirstWalletRefundOrchestrator` → CW credit then spoke with `central_wallet_ledger_entry_id` | Desk CW | Target steady state |
| **rdservice.in** | `DeskWalletRefundCreditService::credit()` without `central_wallet_ledger_entry_id` | **Yes** (`users_wallet`, `LocalWalletSpendableBalance`) | Deploy audit branch; migrate legacy rows |
| **rdservice.in** | `DeskWalletRefundCreditService::creditDeskAuthoritativeAudit()` with ledger id | Audit only | Steady state under desk-first |
| **radiumbox.com** | Integration `POST …/wallet-refunds` (mirror RDIN contract) | **Assumed yes** until prod SHA verified | E2E verification + desk-first |
| **rdservice.net** | Integration wallet-refunds when `RDSERVICE_NET_WALLET_REFUND_CREDIT_ENABLED` | Per spoke config | E2E verification + desk-first |
| **Spokes** | Independent wallet balance UI / checkout debits without Desk `wallet-visibility` | Local ledger | C360 + spoke read APIs only |

**Customer 360 (Desk):** `Customer360Controller::walletLedger` + drawer JS — authoritative when CW account exists; must stay consistent with ledger read APIs (see `Customer360WalletLedgerTest`).

## Integration compliance matrix (update after each spoke verification)

| Spoke | Desk CW read API | Desk-first refund | Audit-only spoke handler | Legacy spendable path | Prod verified |
|-------|------------------|-------------------|--------------------------|------------------------|---------------|
| rdservice.in | Partial (balance read / visibility) | Desk v4.1.24 deployed, **flag OFF** | **Not on prod** (handler lacks ledger id branch) | **Active** | **Partial** |
| radiumbox.com | Per CW overlay docs | Client exists | **Unknown** | **Unknown** | **Not verified this release** |
| rdservice.net | Per CW overlay docs | Client exists | **Unknown** | **Unknown** | **Not verified this release** |

## Rollout order

1. Deploy spoke **audit-only** wallet-refund handlers (verify prod SHA).
2. Enable Desk desk-first per spoke allowlist after E2E canary.
3. Run discrepancy report; prepare manifests per cohort; dry-run preflight; owner-approved execute.
4. Expand allowlist only after revoke/audit parity is verified per spoke.

## Rollback

- Disable desk-first flag; new refunds use legacy path (understand in-flight desk-first recovery).
- Do not delete ledger entries; use reversal/migration rollback commands where implemented.

See also: `docs/desk-first-wallet-refund-release-plan.md`, `docs/backup-runbook.md`.
