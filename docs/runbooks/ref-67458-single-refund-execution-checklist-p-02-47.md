# REF-67458 — single desk-first wallet refund execution checklist (P-02-47)

**Authorization:** Owner + finance only. **Not** authorized by readiness or doc PR merges.

**Target:** REF-67458 / `refund_requests.id=450` / **RB1321** / **₹717.00** / Box user **551444**

**Prerequisite docs:** `ref-67458-desk-first-wallet-cutover-readiness-p-02-45.md`

---

## Phase 0 — Stop before starting (any = NO-GO)

- [ ] REF-67458 not already `closed` / no `CW:*` execution reference
- [ ] Owner written approval for first **radiumbox.com** desk-first refund at **₹717**
- [ ] Finance approval recorded
- [ ] Latest Desk backup run **`cloud_uploaded`** (new run same day as RIP)
- [ ] Latest Box `.sql.gz` with **`gzip -t`** + SHA256 recorded
- [ ] Box root restore drill **PASS** on that gzip (disposable DB) **or** explicit Owner risk acceptance
- [ ] PHPUnit **`DeskFirstWalletRefundRef67458RadiumBoxCutoverTest`** green on merged **`origin/main`**

---

## Phase 1 — Pre-RIP read-only (KVM8)

Record timestamp (IST). Abort if any check fails.

| Check | Pass criterion |
|--------|----------------|
| Refund row | `pending_execution`, amount **717.00**, order **RB1321** |
| Desk ledger | **0** credits for `REF-67458` |
| Box `users_wallet` | **0** rows for `REF-67458`; spendable **551444** = **₹0.00** |
| Authoritative guard | `authority_gaps=[]` |
| Lookup RB1321 | HTTP **200**, `rd_order.userid=551444` |
| Allowlist (before change) | **`rdservice.in` only** |

---

## Phase 2 — Allowlist window (Desk only)

1. Set `CENTRAL_WALLET_DESK_FIRST_WALLET_REFUND_SPOKES=rdservice.in,radiumbox.com`
2. `/usr/local/lsws/lsphp84/bin/php artisan config:cache`
3. Re-verify policy allows **RB1321** (read-only UI or tinker on policy)

**Stop if:** config cache fails or allowlist not visible in runtime config.

---

## Phase 3 — Execute refund (once)

1. Ops **Complete** wallet refund REF-67458 (UI) — **one** attempt unless partial-failure playbook applies.
2. **Do not** run parallel legacy Box wallet credit APIs.

**Stop immediately if:**

- Session error before completion and **no** desk ledger row (investigate; do not loop blindly)
- Desk ledger exists but refund still `pending_execution` → **partial failure** (Phase 5, retry Complete only)
- Amount mismatch errors from Box spoke
- Second desk ledger credit for same reference (should not occur — **halt**, Owner incident)

---

## Phase 4 — Post-success reconciliation (before allowlist revert)

| Artifact | Expected |
|----------|----------|
| Desk ledger | **1** posted credit, `business_reference=REF-67458` |
| Refund row | `closed`, `execution_reference_no=CW:{id}` |
| Box audit | **1** row: `status=audit`, `desk_refund_reference=REF-67458`, `source_system=radium_desk_central_wallet`, `userid=551444`, `credit=717.00` |
| Legacy spendable | **0** new spendable credits for REF-67458 |
| Spendable balance | Box user **551444** spendable unchanged (audit non-spendable) |

**Stop if:** metadata matches REF-67445 row **2588** pattern (NULL reference) without remediation plan.

---

## Phase 5 — Partial failure recovery

| State | Action |
|--------|--------|
| Desk credit + spoke pending | **Complete** again (same refund); verify idempotent ledger count **1** |
| Spoke permanent error after desk credit | **Stop**; Owner chooses retry after Box fix vs desk-first **revoke** (ledger reversal + Box audit retirement) |
| Refund marked completed without Box audit | **Stop** — incident; do not run second refund |

See readiness runbook § partial-failure.

---

## Phase 6 — Mandatory revert

1. `CENTRAL_WALLET_DESK_FIRST_WALLET_REFUND_SPOKES=rdservice.in`
2. `config:cache`
3. Confirm **RB*** desk-first blocked again

---

## Phase 7 — Sign-off

- [ ] Finance reconciliation Desk **CW:{id}** ↔ Box audit ↔ Cashfree/order evidence
- [ ] Ledger prompt row appended (separate authorized doc commit)
- [ ] No other refunds touched

**NO — Not performed by this checklist file:** execution itself; backups; allowlist changes; credits.
