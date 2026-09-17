# Desk operational reference series (RadiumDesk-P-17-09-16)

Independent display/operational numbering for refunds, service orders, and Product POS sales.

## Series (locked)

| Domain | Legacy format | New format | Floor | Counter row |
| --- | --- | --- | --- | --- |
| Refund | `REF-YYYY-NNNNNN` | `REF-{integer}` | `REF-67315` | `reference_sequences.refund_operational` |
| Service Order | `SVC-000xxx` (DB id) | `SVC-{integer}` | `SVC-671` | `reference_sequences.service_order_operational` |
| Product POS | `POS-000xxx` (DB id) | `POS-{integer}` | `POS-6720` | `reference_sequences.product_pos_operational` |

**Not changed:** statutory `INV-*`, customer payments `CP-*`, wallet references, customer IDs, payment references.

## Coexistence rules

- **Refund:** legacy `REF-YYYY-NNNNNN` rows are never renumbered. New allocator ignores year-formatted references when advancing the counter. Only `REF-{n}` with `n >= 67315` participates in max calculation.
- **Service Order:** legacy zero-padded `SVC-0+` values remain. New values are unpadded `SVC-{n}` with `n >= 671`.
- **Product POS:** legacy zero-padded `POS-0+` values remain. New values are unpadded `POS-{n}` with `n >= 6720`. `inventory_sales.invoice_number` (`INV-*`) is unchanged.

## Implementation

- `OperationalReferenceSequenceService` — transactional `lockForUpdate` on `reference_sequences` (same pattern as `IncidentReferenceService`).
- Migration `2026_09_17_200000_initialize_operational_reference_sequences` seeds counters from existing operational-format max values, floored at `floor - 1`.
- Generators: `RefundReferenceService`, `ServiceOrderReferenceService`, `ProductPosReferenceService`.
- Regression tests under `tests/Unit/OperationalReference/` and `tests/Feature/OperationalReference/`.

## Regression lock

Do **not** restore ID-based `SVC-%06d` / `POS-%06d` generators or year-based refund references for new records. Tests fail if new records use legacy formats.
