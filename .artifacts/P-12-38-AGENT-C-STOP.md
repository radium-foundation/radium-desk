# P-12-38 Agent C — STOP (Owner decision required)

**Agent:** C — POS statutory snapshot + PDF  
**Worktree:** `.worktrees/p12-38-pos-statutory-reference` → `feature/p12-38-pos-statutory-reference`  
**Baseline:** `origin/main` @ `762cf798`  
**Stop reason:** Statutory **invoice PDF presentation** for desk POS — **Reference No.** on **UNPAID** (allocation-backed) invoices  
**Agent action:** Document only — **no** product/renderer/payment-summary changes without Owner

---

## 1. Failing matrix row

| Matrix row | Test |
| --- | --- |
| `StatutoryInvoicePdfCustomerFacingSkuTest::test_product_pos_integration_pdf_omits_sku_but_preserves_financial_columns` | Asserts PDF contains `Reference No.` and `UPI-SKU-TEST` after `PosSaleService::completeSale()` (UPI + explicit reference) |

**Focused run (2026-11-12, IST):**

```text
PHP_INI_SCAN_DIR= /opt/homebrew/bin/php -n \
  -d extension=/opt/homebrew/lib/php/pecl/20250925/imagick.so \
  vendor/bin/phpunit \
  tests/Feature/StatutoryInvoice/StatutoryInvoicePdfCustomerFacingSkuTest.php \
  --filter test_product_pos_integration
```

**Result:** **FAIL** — assertion at line 247: extracted PDF text contains `Payment Status` / `UNPAID` / `Mode of Payment` / `UPI` but **not** `Reference No.` (nor `UPI-SKU-TEST` in payment block).

**Same behaviour on sibling regression:** `StatutoryInvoicePdfSerialGroupingAndServicePoTest::test_product_pos_keeps_reference_number_in_payment_details` also **FAIL** on the same worktree (UNPAID + no Reference No.) — confirms this is **product semantics**, not a one-off assertion typo.

---

## 2. Observed product behaviour (current `main` + worktree)

1. **Desk POS** statutory invoices use **allocation-backed** payment evidence (`StatutoryInvoicePaymentReadService::usesAllocationBackedPaymentEvidence` → `DeskPos`).
2. Test `completeSale(..., paymentMethod: 'UPI', paymentReference: 'UPI-SKU-TEST', ...)` mints an invoice with **no** `PaymentAllocation` rows → `amountReceived = 0` → status **`UNPAID`** (`allocationBackedStatus`).
3. `StatutoryDocumentService` passes `paymentStatus: UNPAID` and `paymentReference` from the invoice row into `StatutoryInvoicePdfPayload`.
4. `SimplePdfRenderer::paymentColumns()` **intentionally omits** the Reference No. column when status is UNPAID:

```php
$unpaid = $status === 'UNPAID';
// ...
if (! $unpaid && trim((string) ($payload->paymentReference ?? '')) !== '' && ! $this->showPoNumberInHeader($payload)) {
    $cols[] = ['Reference No.', $this->display($payload->paymentReference)];
}
```

5. **Contrast (Service POS):** `desk_service` PO numbers render under **Order ID** as **PO Number**; Reference No. is suppressed when shown as PO (see v4.0.115 / `StatutoryInvoicePdfSerialGroupingAndServicePoTest::test_desk_service_po_number_...`). **This STOP is desk POS counter UPI/tender reference, not service PO header.**

---

## 3. Owner decision required (choose one policy)

**Question:** For a **desk POS** statutory invoice that is **financially UNPAID** (no finance `PaymentAllocation` yet) but was completed at the counter with a **tender reference** (`inventory_sales.payment_reference` / `statutory_invoices.payment_reference` e.g. `UPI-SKU-TEST`), should the customer-facing PDF **Payment Details** block show **Reference No.**?

| Option | Policy | Typical implementation direction | Test impact |
| --- | --- | --- | --- |
| **A — Show tender reference while UNPAID** | Counter tender/UPI txn id is customer-visible even before finance allocation | Adjust `SimplePdfRenderer::paymentColumns()` and/or payload rules so desk POS **tender** reference displays when `payment_reference` is set and not reserved `__PAYMENT_PENDING__`, independent of allocation-backed UNPAID | Keep / restore `StatutoryInvoicePdfCustomerFacingSkuTest` + serial-grouping POS reference tests |
| **B — Hide until paid (current renderer)** | Reference No. is **finance receipt** evidence only; omit until allocation-backed status is Paid/Partial | **No renderer change**; update tests to seed `PaymentAllocation` (or assert **absence** of Reference No. when UNPAID) | Align expectations with UNPAID PDF |
| **C — Split labels** | Show counter tender under a **new label** (e.g. “UPI Txn” / “Tender ref”) when UNPAID; keep “Reference No.” for allocated payments only | Renderer + copy decision; may need Owner-approved label | New assertions; migration of existing tests |

**Out of scope for Agent C without Owner:** minting logic, tax lines, e-invoice, automatic payment posting, or changing when POS sales are considered “paid” in finance.

**Recommendation for Owner review:** Option **A vs B** — tests and v4.0.115-era POS PDF regressions assume **A**; allocation-backed UNPAID presentation implements **B** for Reference No. specifically (while still showing **Mode of Payment: UPI**).

---

## 4. PosStatutorySnapshotTest — partial (not STOP)

**Harness:** `PosStatutorySnapshotTest::setUp()` + `withoutVite()` (test-only; committed on agent branch).

**Focused run:**

```text
PHP_INI_SCAN_DIR= /opt/homebrew/bin/php -n vendor/bin/phpunit tests/Feature/Inventory/PosStatutorySnapshotTest.php
```

**Result:** **9/14 PASS**, **4 FAIL**, **1 ERROR** (class total 14; matrix owns 5 methods).

| Matrix method | Post-harness status | Notes |
| --- | --- | --- |
| `test_customer_gstin_from_the_form_is_snapshotted_...` | **ERROR** | `InventorySale` not found after flow — product/session fixture |
| `test_show_and_reprint_do_not_mint_a_statutory_invoice` | **FAIL** | Counter show HTML — missing expected “Sale statutory snapshot” copy |
| `test_upi_intent_carries_the_sale_snapshot_through_to_complete` | **FAIL** | `billing_city` snapshot null vs `New Delhi` |
| `test_b2b_sale_requires_city_state_and_pin` | **FAIL** | Session errors: city/pin messages; **missing** `billing_state` key |
| `test_b2b_sale_rejects_missing_state` | **FAIL** | Session missing `errors` bag |

These require **B2B validation / UPI intent / counter UI** product review — **not** committed by Agent C in this round.

---

## 5. Agent C deliverables this round

| Deliverable | Status |
| --- | --- |
| STOP artifact (this file) | **DONE** |
| Test harness `withoutVite()` only | **Committed** on `feature/p12-38-pos-statutory-reference` (see commit SHA in Agent C return) |
| Product/statutory changes | **NONE** |
| PR | **NONE** (harness-only commit; no independent merge without Owner on PDF row) |

---

## 6. References

- Ownership: `P-12-38-OWNERSHIP-MANIFEST.md` (Agent C paths)
- Matrix: `P-12-38-MATRIX.md` cluster C
- Code: `app/Services/StatutoryInvoice/SimplePdfRenderer.php` (`paymentColumns`)
- Code: `app/Services/StatutoryInvoice/StatutoryInvoicePaymentReadService.php` (desk POS allocation-backed UNPAID)
- Historical: v4.0.115 invoice PDF POS reference behaviour (`CHANGELOG.md`, P-22-09-15 ledger)
