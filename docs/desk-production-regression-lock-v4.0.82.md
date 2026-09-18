# Production regression lock — v4.0.82

**Baseline release:** `v4.0.82`  
**Baseline SHA:** `5186f4a6`  
**Production server:** KVM8 `187.127.129.16` → `/var/www/radium-desk`  
**Lock recorded:** 2026-09-18 (`RadiumDesk-P-18-09-24`)  
**Rollback target:** `v4.0.81` / `9e00a510`

This document is the permanent regression contract for behavior verified in production through **v4.0.82**. It does **not** authorize silent redesign of protected flows.

## Policy

1. **Production-verified contract** — observed working on production during controlled UAT or operational verification.
2. **Automated regression coverage** — PHPUnit / contract scripts that fail if protected behavior regresses.
3. **STOP → FIX → RE-TEST** — any protected regression must be fixed and re-verified before release; do not weaken or delete tests to pass CI.
4. **No silent recreation** — missing or changed protected behavior must not be reintroduced under new semantics without explicit owner approval and lock update.

## Known production data (not defects)

| Item | Status |
| --- | --- |
| `SVC-000002` | Open / unpaid / uninvoiced by owner decision — do not auto-close or invoice in regression work |
| Flagged ₹100 idempotency test payment (customer 769) | Untouched unless separately authorized |

---

## Service POS contracts

| Contract | Production verified | Automated coverage | Notes |
| --- | --- | --- | --- |
| DEV catalog active (`DEV-RD-1Y`, `DEV-AMC-1Y`, `DEV-CUSTOM-MS`) | P-18-09-22 | `ServicePosFoundationTest::test_dev_catalog_seeded_with_expected_sac_codes` | SAC 998313 / 998596 |
| Quote → service order conversion | P-18-09-22 | `ServicePosFoundationTest::test_quote_conversion_creates_exactly_one_service_order`, `ServicePosUiTest` | |
| Operational numbering `SVC-671+` | P-18-09-22 | `ServicePosFoundationTest::test_first_operational_service_order_number_is_svc_671`, `OperationalReferenceIntegrationTest` | Legacy `SVC-000xxx` coexistence locked in `docs/desk-operational-reference-series-p-17-09-16.md` |
| Duplicate quote conversion idempotent | P-18-09-22 | `ServicePosFoundationTest::test_duplicate_quote_conversion_is_idempotent` | |
| Quote create idempotency key | P-18-09-24 (lock) | `ServicePosFoundationTest::test_quote_create_idempotency_key_returns_existing_quote` | |
| Statutory service invoice issuance | P-18-09-22 | `ServicePosFoundationTest::test_statutory_invoice_issued_from_service_order`, `ServiceStatutoryIssuanceTest` | DeskService channel |
| Duplicate invoice issuance idempotent | P-18-09-22 | `ServicePosFoundationTest::test_duplicate_invoice_issuance_is_idempotent` | |
| SAC/GST line persistence | P-18-09-22 | `ServicePosFoundationTest::test_quote_preserves_sac_gst_and_price_snapshots`, GST split tests | |
| Intra-state GST: CGST + SGST | P-18-09-23 | `ServicePosGstSplitTest::test_intra_state_desk_service_invoice_persists_cgst_sgst`, `ServiceGstSplitIssuanceTest` (commerce path) | Production: SVC-672 / INV-673076 |
| Inter-state GST: IGST | P-18-09-23 | `ServicePosGstSplitTest::test_inter_state_desk_service_invoice_persists_igst`, `ServiceGstSplitIssuanceTest` (commerce path) | Production: SVC-673 / INV-673077 |
| Statutory invoice PDF generation | P-18-09-22 / P-18-09-23 | `ServicePosGstSplitTest::test_desk_service_invoice_pdf_generation_succeeds`, `ServiceGstSplitIssuanceTest` (commerce PDF) | |
| Partial → final payment lifecycle | P-18-09-22 | `ServicePosFoundationTest::test_partial_then_final_payment_updates_order_status` | |
| Payment idempotency | P-18-09-22 | `ServicePosFoundationTest::test_payment_and_allocation_idempotency` | |
| Payment over-allocation rejected | P-18-09-22 | `ServicePosFoundationTest::test_over_allocation_is_rejected` | |
| Service POS inventory isolation | P-18-09-22 | `ServicePosFoundationTest::test_service_pos_does_not_mutate_hardware_inventory` | No product/serial/reservation mutation |
| Unauthorized Service POS access → 403 | P-18-09-22 | `ServicePosUiTest::test_agent_cannot_access_service_pos_counter` | |

**Production UAT anchors:** SQ-2026-000003 → SVC-671 → INV-673062 (paid); GST splits SVC-672/673.

---

## GST contracts (DeskService path)

Locked split rules for `StatutoryInvoiceService::issueFromServiceOrder()`:

- Same POS + billing state → **CGST + SGST** (18% → 38.06 + 38.06 on ₹422.88 taxable / ₹499 line).
- Different POS state → **IGST** (76.12 on same taxable base).
- SAC **998313** preserved on catalog lines.

Primary regression: `tests/Feature/ServicePos/ServicePosGstSplitTest.php`.

---

## Payment contracts

| Contract | Coverage |
| --- | --- |
| Partial payment leaves order `partially_paid` | `ServicePosFoundationTest::test_partial_then_final_payment_updates_order_status` |
| Duplicate payment/allocation keys | `ServicePosFoundationTest::test_payment_and_allocation_idempotency` |
| Over-allocation validation | `ServicePosFoundationTest::test_over_allocation_is_rejected` |

---

## Inventory isolation

Service POS must not create inventory movements, reservations, sales, or serial allocations. Locked by `ServicePosFoundationTest::test_service_pos_does_not_mutate_hardware_inventory`.

---

## Hardware / shipping contracts

| Contract | Production verified | Automated coverage | Notes |
| --- | --- | --- | --- |
| RBP222 pickup reconciliation (Out for Pickup / status 19) | P-18-09-21 (fulfilment 948 / AWB 77191051976) | `HardwareFulfilmentOperationalWorkflowTest` pickup reconciliation tests; `ShiprocketTrackingNormalizerTest` | v4.0.82 release |
| RBP103 measured-parcel fulfilment | P-18-09-16 (v4.0.80) | `HardwareNonSerializedMultiSkuParcelTest`, `HardwareFulfilmentParcelSnapshotTest`, `HardwareFulfilmentOperationalClassifierTest` | Multi-SKU quantity stock |
| WM112 mapping / fulfilment | P-18-09-16 (v4.0.80) | `SeedRadiumboxHardwareSkuMapsCommandTest`, `HardwareReadyQueueVariantDisplayTest` | model_id 340 → RBWM112MZ |
| AWB / courier / label behavior | P-18-09-09 (v4.0.77) | `HardwareFulfilmentCourierWorkflowTest`, `HardwareFulfilmentP5ShipmentTest`, AWB gateway unit tests | Alternate courier recovery |
| RBP222 invoice state before PDF (v4.0.81) | P-18-09-18 | `HardwareFulfilmentInvoicePdfFailureTest` | Fulfilment 948 recovery path |
| Historical duplicate fulfilment cancellation | P-18-09-26 (RDE318338 / fulfilment 932) | `HardwareHistoricalDuplicateFulfilmentCancellationTest` | Safely terminate duplicate fulfilment, release allocated serial after ownership validation, cancel duplicate Desk invoice via `StatutoryInvoiceService::cancel()`, preserve shipment/provider evidence, exclude from Ready Queue/shipment workflows; **no Shiprocket mutation** |
| Hardware POS | prior releases | `PosSaleServiceTest`, POS statutory/e-invoice suites | Out of scope for v4.0.82 UAT |

---

## Dashboard / Ready Queue contracts

| Contract | Production verified | Automated coverage | Deploy guard |
| --- | --- | --- | --- |
| P-302 Hardware Dashboard | P-17-09-18 / v4.0.75+ | `HardwareDashboardNeedsActionQueueTest` | `tools/contracts/hardware-dashboard-p302.manifest` |
| Service Ready Queue | P-18-09-03 / v4.0.76+ | `ReadyQueueCapabilityAccessTest`, hardware queue tests | `tools/contracts/ready-queue-service.manifest` |

Contract verify scripts: `tests/scripts/verify-hardware-dashboard-contract.test.sh`, `tests/scripts/verify-ready-queue-contract.test.sh`.

---

## Operational reference series

See `docs/desk-operational-reference-series-p-17-09-16.md`. Service orders use unpadded `SVC-{n}` with floor **SVC-671**.

---

## Remaining coverage gaps

| Gap | Risk | Mitigation |
| --- | --- | --- |
| Full HTTP PDF download for Service POS UI | Low | Domain PDF generation locked; UI download not separately exercised in PHPUnit |
| Production-only payment gateway rails | Low | Domain payment/allocation idempotency locked in foundation tests |
| Live Shiprocket API variance | Medium | Fake gateway + production reconciliation playbook (P-18-09-21) |
| Shipment issues outside verified contracts | Out of scope | Not part of v4.0.82 lock; separate fix tasks |

---

## Test commands (focused)

```bash
php artisan test tests/Feature/ServicePos
php artisan test tests/Feature/OperationalReference
php artisan test tests/Feature/HardwareFulfilment/HardwareHistoricalDuplicateFulfilmentCancellationTest.php
php artisan test tests/Feature/HardwareFulfilment/HardwareFulfilmentOperationalWorkflowTest.php --filter pickup
bash tests/scripts/verify-hardware-dashboard-contract.test.sh
bash tests/scripts/verify-ready-queue-contract.test.sh
```
