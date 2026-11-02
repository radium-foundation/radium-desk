# REF-67458 desk-first wallet cutover — readiness runbook (P-02-45)

**Prompt ID:** P-02-45  
**Refund:** REF-67458 / Desk `refund_requests.id=450` / **RB1321** / **₹717.00 INR** / Box user **551444**  
**Purpose:** Engineering and recovery preparation only. **Not** an execution authorization.

---

## Expected financial artifacts (successful single execution)

| System | Artifact | Expected count | Required fields |
|--------|----------|----------------|-----------------|
| Desk | `central_wallet_ledger_entries` credit | **1** | `business_reference=REF-67458`, posted status |
| Desk | `refund_requests` | **1** row completed | `execution_reference_no=CW:{ledger_id}`, `execution_transaction_id={ledger_id}` |
| Box | `users_wallet` audit row | **1** | `status=audit`, `desk_refund_reference=REF-67458`, `source_system=radium_desk_central_wallet`, `userid=551444`, `credit=717.00` |
| Box | Legacy spendable credit for REF-67458 | **0** | No row with `source_system` null / `radium_desk` spendable for this reference |

**Note:** Box HTTP JSON may return `source_system=radium_desk` in the envelope; **reconciliation must verify the DB row** uses `radium_desk_central_wallet` (67446-style), not REF-67445 row **2588** (NULL metadata).

---

## Production pre-checks (read-only, immediately before RIP)

Observed **2026-11-02 ~19:31 IST** on KVM8:

| Check | Result |
|--------|--------|
| REF-67458 status | `pending_execution`, no execution refs |
| Desk CW ledger for REF-67458 | **0** |
| Box `users_wallet` for REF-67458 | **0** |
| Box spendable balance user **551444** | **₹0.00** |
| `WalletRefundAuthoritativeCreditGuard` | `authority_gaps=[]`, assert **OK** |
| Lookup RB1321 | HTTP **200**, `orders_id=null`, `rd_order.userid=551444` |
| Spoke allowlist (prod) | **`rdservice.in` only** — blocks UI until changed |

---

## Temporary `radiumbox.com` allowlist — add and revert

**Do not** run these steps in a readiness prompt; Owner-authorized execution only.

### Pre-change

1. Fresh **Desk** encrypted backup via `/var/backups/radium-desk/backup-run.sh` (confirm `manifest.json` → `cloud_uploaded`).
2. Fresh **Box** gzip (see Box backup section below).
3. Record current value: `CENTRAL_WALLET_DESK_FIRST_WALLET_REFUND_SPOKES=rdservice.in`.

### Add (single-refund window)

1. On Desk production `.env`, set:  
   `CENTRAL_WALLET_DESK_FIRST_WALLET_REFUND_SPOKES=rdservice.in,radiumbox.com`
2. `php artisan config:cache` (use production PHP binary, e.g. `/usr/local/lsws/lsphp84/bin/php`).
3. Verify cached config (tinker or read-only grep of bootstrap cache) before **Complete**.

### Revert (mandatory, same session)

1. Restore allowlist to: `CENTRAL_WALLET_DESK_FIRST_WALLET_REFUND_SPOKES=rdservice.in`
2. `php artisan config:cache` again.
3. Confirm `DeskFirstWalletRefundSpokePolicy` blocks `RB*` orders in UI (automated test: `DeskFirstWalletRefundMultiSpokeTest::test_default_allowlist_blocks_radiumbox_orders`).

**Allowlist change alone is insufficient:** backups, pre-RIP verification, post-execution reconciliation, and finance sign-off remain required.

---

## Box database backup and verified restore

Desk uses scheduled encrypted backups under `/var/backups/radium-desk/runs/` (latest **`20261102T114147Z`**, `cloud_uploaded`).

Box has **no** equivalent scheduled job in the Desk pipeline. Established manual artifacts:

| Artifact | Path | Integrity (P-02-45) |
|----------|------|------------------------|
| Pre–REF-67446 | `/var/backups/radiumbox-prod/radiumbox_prod-pre-P-01-15-ref-67446-20261101T044223Z.sql.gz` | **`gzip -t` OK** (~32s) |
| Pre–P-01-44 | `/var/backups/radiumbox-prod/radiumbox_prod-pre-P-01-44-.sql.gz` | Present (~1.0 GB) |

### Create pre-RIP Box backup (Owner / root)

```bash
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
OUT="/var/backups/radiumbox-prod/radiumbox_prod-pre-REF-67458-${STAMP}.sql.gz"
# Use credentials from /var/www/radiumbox.com/.env — run as root or privileged operator
mysqldump -h127.0.0.1 -u"$DBU" -p"$DBP" --single-transaction --routines --triggers "$DBN" | gzip -c > "$OUT"
gzip -t "$OUT" && sha256sum "$OUT" | tee "${OUT}.sha256"
```

### Verified restore (elevated route — P-02-43 follow-up)

**Problem:** `radiumbox_prod` app user cannot `CREATE DATABASE` / `IMPORT` for isolated drills.

**Approved drill pattern (mirrors Desk P-02-35 `radium_desk_p0235_drill`):**

1. **Root** (or sudo) creates disposable DB: `radiumbox_prod_ref67458_drill`.
2. `gunzip -c "$OUT" | mysql -h127.0.0.1 radiumbox_prod_ref67458_drill` as root.
3. Verify row counts / sample queries (`users_wallet` max id, `order_rdservice` for RB1321).
4. `DROP DATABASE radiumbox_prod_ref67458_drill;`

**P-02-45:** `gzip -t` on existing dumps **PASSED**; **full root import drill NOT RUN** (no disposable DB created on prod in this prompt). Execution prompt must either run the drill or accept explicit Owner risk.

**Dump alone does not prove recoverability** until step 2–3 succeed once per backup generation.

---

## Partial failure, reversal, and reconciliation

Reference: `docs/desk-first-wallet-refund-state-machine.md`.

### Partial completion (Desk credited, Box audit pending)

| Signal | Action |
|--------|--------|
| Refund still `pending_execution`, CW credit exists for REF-67458 | **Complete** again (same refund) — idempotent spoke POST with same `central_wallet_ledger_entry_id` |
| UI yellow **Desk-first recovery** banner | Same as above |

**Do not** manually insert Box wallet rows. **Do not** run legacy Box-only wallet credit without Desk ledger id.

### Permanent spoke failure after Desk credit

1. Stop repeated blind clicks; capture Desk ledger id and HTTP error body.
2. Owner decision: **retry Complete** after fixing Box, or **revoke** desk-first path:
   - Desk CW **debit** (revoke flow / `CentralWalletRefundLedgerReversalService`)
   - Box audit **retirement** via `POST /api/integrations/v1/wallet-refund-reversals` (route **present** on prod `routes/api.php`)
3. Finance reconciliation: exactly one CW credit reversed ↔ audit retired; refund returns to approved/pending per revoke policy.

### Post-success reconciliation checklist

1. Desk: one posted credit, refund `CW:{id}` matches ledger id.
2. Box: one audit row with metadata above; `LocalWalletSpendableBalance::spendableForUser(551444)` unchanged except CW spendable on Desk (not Box).
3. Zero legacy spendable `users_wallet` for REF-67458.
4. Allowlist reverted to `rdservice.in` only.

---

## Automated regression (local)

```bash
cd radium-desk/.worktrees/p02-40-backlog-classify
./vendor/bin/phpunit tests/Feature/CentralWallet/DeskFirstWalletRefundRef67458RadiumBoxCutoverTest.php
```

Related suites: `DeskFirstWalletRefundRecoveryTest`, `DeskFirstWalletRefundFailureInjectionTest`, `DeskFirstWalletRefundMultiSpokeTest`.

---

## Remaining blockers for execution prompt

1. Owner + finance authorization for first **radiumbox.com** desk-first refund at ₹717.
2. Temporary allowlist ceremony (above) + mandatory revert.
3. **New** Desk backup + **new** Box gzip + **verified** restore drill (root path).
4. Post-RIP read-only re-verify REF-67458 + user 551444 wallet state.
5. Live execution + reconciliation sign-off (separate prompt).

---

**NO — Not performed:** refund completion; wallet credits/debits; production allowlist or flag changes; production DB writes; queue/worker/deploy actions.
