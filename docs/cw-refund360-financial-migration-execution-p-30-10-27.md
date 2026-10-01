# Central Wallet — Refund 360 Financial Migration EXECUTION

**Prompt ID:** `RadiumDesk-P-30-10-27`  
**Date:** 2026-10-01  
**Owner approval:** `R-CW-REF360-FIN-MIG-20261001-001`  
**Mode:** Financial execution — **₹849 migrated**

---

## Batch

`desk-refund-wallet-migration-refund360-p30-10-26`

| refund_id | reference | amount | CWID | source_wallet |
|---:|---|---:|---|---:|
| 360 | REF-67363 | ₹849 | `5a3d0706-9f4b-4adc-b3d8-7cd134295404` | 2567 |

---

## Command

```bash
php artisan central-wallet:refund360-migration-execute \
  --force \
  --owner-approval-ref=R-CW-REF360-FIN-MIG-20261001-001 \
  --refund-id=360
```

---

## Flags (restored OFF after execution)

| Flag | Site | During | After |
|---|---|:---:|:---:|
| `CENTRAL_WALLET_REFUND_MIGRATION_EXECUTION_ENABLED` | Desk | ON | **OFF** |
| `CENTRAL_WALLET_BALANCE_MIGRATION_EXECUTION_ENABLED` | Desk | ON | **OFF** |
| `CENTRAL_WALLET_MIGRATION_LOCK_ENABLED` | radiumbox.com | ON | **OFF** |
| `CENTRAL_WALLET_MIGRATION_RETIREMENT_ENABLED` | radiumbox.com | ON | **OFF** |
