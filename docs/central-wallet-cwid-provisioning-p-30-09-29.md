# Central Wallet — Controlled CWID Provisioning + Exception Resolution (P-30-09-29)

**Prompt ID:** `RadiumDesk-P-30-09-29`  
**Mode:** Identity/CWID provisioning only — **no financial migration**

---

## Summary

| Metric | Value |
|---|---|
| Population | **292** refunds / **₹165,708.00** |
| Customers provisioned (new) | **15** (+ User 3 pre-existing) |
| `central_customers` on production | **16** |
| `central_wallets` on production | **17** (no ledger credits) |
| Ambiguous resolved | **7** / ₹3,456 |
| Ambiguous unresolved | **3** / ₹1,497 |
| Identity-insufficient unresolved | **55** / ₹36,512 |
| `READY_FOR_EXECUTION` | **2** / ₹996 (User 3 + 1 provisioned) |
| `EXECUTION READY` | **NO** |

---

## Ambiguous refunds (10)

**Resolved (7)** via order-prefix authoritative spoke + matching wallet user + order user alignment: refunds **253, 254, 256, 257, 262, 264, 267**.

**Unresolved (3)** — wallet user ≠ order user on RD* orders: **263, 265, 266** (₹1,497). Owner must select authoritative spoke/wallet.

---

## CWID provisioning (226 candidates)

Only customers with **trusted Google** or **verified email** on the spoke `users` table were auto-provisioned (**15** customers, **14** in batch run + **1** pilot).

**210** candidates lack trusted credential evidence → remain **`CWID_REQUIRED`** pending controlled M2 ceremony or Owner identity.

---

## Financial invariants (unchanged)

| Metric | Before | After |
|---|---:|---:|
| `central_wallet_ledger_entries` | 0 | 0 |
| Ledger sum | ₹0 | ₹0 |
| Refund rows / amounts | unchanged | unchanged |
| Spoke wallet balances | unchanged | unchanged |

---

## Artifacts

| File | Purpose |
|---|---|
| `storage/app/private/cw-migration-final-manifest-p30-09-29.json` | Complete 292-row final manifest |
| `storage/app/private/cw-migration-final-manifest-p30-09-29.csv` | CSV export |
| `storage/app/private/cw-migration-cwid-provisioning-final-p30-09-29.json` | Provisioning outcomes |
| `storage/app/private/cw-migration-owner-resolution-final-p30-09-29.json` | Unresolved Owner rows only |
| `storage/app/private/cw-migration-reconciliation-p30-09-29.json` | Reconciliation summary |
| `storage/app/private/cw-identity-finalize-p30-09-29.py` | Production finalize + provision script |

---

## Remaining blockers

1. **3** ambiguous spoke collisions (₹1,497)
2. **55** identity-insufficient refunds (₹36,512)
3. **232** refunds still `CWID_REQUIRED` (₹126,703) — ceremony/trusted identity
4. Financial migration execution not authorized
