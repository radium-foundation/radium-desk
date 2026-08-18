# P15-08-023 — orders.serial_number FPSPL1141XX conflict

Canvas: `/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p15-08-023-serial-conflict.canvas.tsx`

Read-only. No apply, no row changes, no LCDS code changes.

Generation: `tier1-rehearsal-20260815-085333-90fe9d`  
Conflict: `orders.serial_number = FPSPL1141XX`, source PK 1520 vs target PK 1458.

## Classification: A (historical serial reuse) + D (LCDS unique policy)

Not B (the two PKs are not the same order split across hosts).  
Not C (VPS 1458 is not stale; it matches Hostinger 1458).

Both databases already contain **eight** distinct live orders with this serial. Hostinger 1520 already exists on VPS as id 1520 with matching fields. Apply failed because UniqueConflictChecker treats `serial_number` as a business unique key and `first()` hits 1458 while upserting 1520.

## Hostinger 1520

| Field | Value |
|-------|--------|
| order_id | RD3433410 |
| serial | FPSPL1141XX |
| customer | Prabhash Jha / jprabhash@outlook.in / 9930310003 |
| device | MFS110 / device_model_id 1 |
| cashfree_payment_id | 5891750548 |
| gateway_order_id | 6287031592 |
| amount / method | 599.00 UPI |
| status | active, deleted_at null |
| created_at | 2026-06-28 19:01:00 |
| updated_at | 2026-07-13 17:07:45 |
| serial_entered_at | null |
| incident | SC01516 (id 1516), closed |

## VPS 1458

| Field | Value |
|-------|--------|
| order_id | RD3433314 |
| serial | FPSPL1141XX |
| customer | sandeep / sandeepcomputer9@gmail.com / 9838990529 |
| device | MFS 110 / device_model_id 1 |
| cashfree_payment_id | 5891320470 |
| gateway_order_id | 6286552043 |
| amount / method | 599.00 UPI |
| status | active, deleted_at null |
| created_at | 2026-06-28 17:15:38 |
| updated_at | 2026-07-13 17:07:45 |
| serial_entered_at | 2026-06-28 19:33:53 (user 1) |
| incident | SC01454 (id 1454), resolved |

Same-PK cross-host: Hostinger 1458 = VPS 1458; Hostinger 1520 = VPS 1520.

## Dependents (both hosts, same)

| Order PK | incidents | refunds | CDC | restorations | webhook payload mentions |
|----------|-----------|---------|-----|--------------|--------------------------|
| 1520 | SC01516 closed | 0 | 0 | 0 | 1 |
| 1458 | SC01454 resolved | 0 | 0 | 0 | 1 |

## Other orders with this serial (8 on each host)

1458, 1520, 1608, 1682, 1725, 1953, 1958, 1967 — distinct `order_id`, customers, and Cashfree payment IDs. `device_model_assigned_at` clustered at 2026-07-13 17:07:45–46.

## Recommended next action

Do **not** merge, delete, or rewrite these orders. Decide LCDS apply policy for `orders.serial_number`: production already allows duplicates (index, not UNIQUE). Resume apply only after that policy decision. Preserve the conflict JSON.
