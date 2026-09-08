# Additive hardware split-tender — RadiumDesk-P-07-09-100

**Date:** 2026-09-08  
**Prompt ID:** `RadiumDesk-P-07-09-100`  
**Mode:** Application/schema implementation. No production write. No RDE318437 delivery.

Last used ledger ID: **P-07-09-99**. This ticket: **P-07-09-100**.

---

## Chosen representation

Nullable columns on `commerce_orders`:

- `wallet_tender_amount`
- `wallet_tender_reference`

Handoff contract: optional `tenders[]` with exactly one `cashfree` and one `wallet` when present.

| Why this | Why not the alternative |
|----------|-------------------------|
| Durable, queryable, 1:1 with the commerce order already made unique by `(channel, source_type, source_id)` | Metadata JSON: hasher ignores unknown keys; no uniqueness; not a payment fact |
| Written atomically in the same ingest transaction as the commerce order | New `commerce_order_tenders` table: not required for one wallet per order |
| Cashfree SoT stays on support `orders` + existing fulfilment link | Extending `hardware_fulfilment_payment_evidence`: unique `cashfree_payment_id` would force a fake id |

---

## Handoff contract

Omitted `tenders` = existing single-Cashfree path (RDE318421).

```json
"tenders": [
  { "type": "cashfree", "amount": 2000, "reference": "6428869383" },
  { "type": "wallet", "amount": 499 }
]
```

- Line totals remain the commercial total (₹2,499). Product/SKU/qty unchanged.
- `cashfree.amount + wallet.amount` must equal line total.
- If a Desk support order exists for `source_id`, Cashfree amount/reference must match it. Support `payment_amount` is not rewritten.
- Wallet reference is optional and is never invented. It must not equal the Cashfree id.
- `tenders` is rejected on non-`radiumbox_com` channels.
- Hardware payload hash includes tenders only when present, so a different wallet is a 409 conflict, not a second commerce order.

---

## Schema

Migration `2026_09_08_220000_add_wallet_tender_to_commerce_orders_table`.

Rollback: drop the two nullable columns. No historical payment rows are rewritten.

---

## Tests (this session)

| Suite | Result |
|-------|--------|
| Focused hasher + split-tender | 13 passed |
| Hardware + channel ingest (+ P2 payment) | 266 tests, 265 passed, 1 skipped |
| Pint | passed |
| `php -l` on changed PHP | passed |
| PHPStan | no config |

---

## Not performed

Production migrate, RDE318437 ingest, commerce/fulfilment/payment writes, Shiprocket, commit, push, deploy.
