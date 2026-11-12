# P-12-45 — Statutory PDF “Embedded invoice image missing: Logo”

**Programme (IST):** 2026-11-12  
**Repository:** `/Users/ravi/RadiumWebsites/radium-desk`  
**Worktree:** `.worktrees/p12-45-statutory-pdf-logo` → `feature/p12-45-statutory-pdf-logo`  
**Baseline verified:** `origin/main` @ `49775d89bba9201a3b4d50ab4aee05e727c51262`  
**Wallet checkout:** `d9481615` — **verified, not modified**  
**Full PHPUnit:** **NOT RUN** (policy P-12-43 / P-12-40)

---

## 1. Ledger

| Field | Value |
| --- | --- |
| Prompt | **P-12-45** |
| Ledger ID | **RadiumDesk-P-12-45** (**CLAIMED**) |
| Scope | Residual matrix rows: RegressionContract ×2 + SerialCapacity ×2 (`Embedded invoice image missing: Logo`) |

---

## 2. Classification (per row)

| Test method | Prior symptom | Classification | Notes |
| --- | --- | --- | --- |
| `StatutoryInvoicePdfRegressionContractTest::test_contract_small_serial_set_renders_inline_only_when_measured_block_fits` | ERROR before assertions | **APP DEFECT** | Layout measurement called `logoMark` without `prepareEmbeddedImages()` |
| `StatutoryInvoicePdfRegressionContractTest::test_contract_non_fitting_small_serial_set_is_all_or_nothing_to_annexure` | ERROR on `resolveFirstPageSerialCount()` | **APP DEFECT** | Same pipeline |
| `StatutoryInvoicePdfSerialCapacityTest::test_ten_or_fewer_serials_use_main_page_only` | ERROR via reflection on layout APIs | **APP DEFECT** + **TEST HARNESS** | Fix renderer; unit class needs Laravel `config()` for CIN in header measurement |
| `StatutoryInvoicePdfSerialCapacityTest::test_multipage_invoice_*` | ERROR masked stale expectation | **APP DEFECT** (Logo) + **TEST FALSE POSITIVE** | Old test never reached assertions; expectation aligned to `render()` (serials → Annexure) |

**Not reproduced as:** ENV/RESOURCE (Imagick loads under `bin/phpunit-isolated`; logo file `public/brand/logo.png` present; `config('branding.logo')` = `brand/logo.png`).

**Production logo visibility (owner evidence):** Full `SimplePdfRenderer::render()` always calls `prepareEmbeddedImages()` first; production PDFs embed `/Logo Do`. Failures were **pre-render layout probes only**, not missing repo assets.

---

## 3. Root cause

`SimplePdfRenderer::estimateFirstPageTableStartY()` drives serial capacity (`ensureSerialLayoutResolved` → `mainPageSerialRegionHeight`). It called `firstPageHeader()`, which invoked `logoMark()` → `imageDraw('Logo', …)` while `embeddedImages['Logo']` was still empty (only populated in `render()`).

Tests that call `resolveFirstPageSerialCount` / `annexureSerials` via reflection **without** `render()` hit this path. RegressionContract tests called measurement helpers before or alongside render.

**Exception site:** `SimplePdfRenderer::imageDraw()` — `StatutoryInvoicePdfAssetException: Embedded invoice image missing: Logo` (distinct from `Authoritative invoice asset missing` or Imagick errors).

---

## 4. Pipeline trace

```
config('branding.logo') → public_path(…) → brandAssetPath()
prepareEmbeddedImages() → rasterBrandAsset() (Imagick) → embeddedImages['Logo']
render() → prepareEmbeddedImages() → invoicePageStreams() → firstPageHeader(embedBrandAssets: true) → logoMark

Layout-only path (pre-fix):
resolveFirstPageSerialCount() → ensureSerialLayoutResolved()
  → mainPageSerialRegionHeight() → estimateFirstPageTableStartY()
  → firstPageHeader() → logoMark()  ← failed without embedded images
```

---

## 5. Reproduction (focused `bin/phpunit-isolated`)

**Baseline @ `49775d89` (pre-fix):**

```text
ERROR Embedded invoice image missing: Logo — 4/4 matrix tests
```

Evidence: `.artifacts/P-12-45-phpunit-logo-cluster.txt` (post-fix **11/11 PASS**).

Commands:

```bash
./bin/phpunit-isolated tests/Feature/StatutoryInvoice/StatutoryInvoicePdfRegressionContractTest.php
./bin/phpunit-isolated tests/Unit/StatutoryInvoice/StatutoryInvoicePdfSerialCapacityTest.php
```

---

## 6. Remediation (implemented)

| Path | Change |
| --- | --- |
| `app/Services/StatutoryInvoice/SimplePdfRenderer.php` | `firstPageHeader(..., bool $embedBrandAssets = true)`; `estimateFirstPageTableStartY()` passes `embedBrandAssets: false` so layout measurement uses header geometry **without** rasterized logo embedding |
| `tests/Unit/StatutoryInvoice/StatutoryInvoicePdfSerialCapacityTest.php` | Extend `Tests\TestCase` + `configureLocationSellerIdentity()` so header measurement can read `config('branding.cin')`; rename/update multipage test to assert render-aligned annexure behaviour |

**No** statutory calculation, QR, or production branding path changes. Logo still embedded on all real PDF page streams.

---

## 7. Post-fix validation

| Suite | Result |
| --- | --- |
| `StatutoryInvoicePdfRegressionContractTest` | **7/7 PASS** |
| `StatutoryInvoicePdfSerialCapacityTest` | **5/5 PASS** |
| Combined matrix cluster | **11/11 PASS** (`duration_ms` ~1239) |

Presentation logo cluster (`StatutoryInvoicePdfPresentationTest` logo filters) remains **6/6 PASS** (unchanged).

---

## 8. Matrix row recommendations (P-12-32 / P-12-44)

| Matrix test FQCN::method | WS-10 status | Recommendation |
| --- | --- | --- |
| `…RegressionContractTest::test_contract_non_fitting_small_serial_set_is_all_or_nothing_to_annexure` | **VERIFIED FIXED** (P-12-45) | Close row; root cause layout measurement, not fixture |
| `…RegressionContractTest::test_contract_small_serial_set_renders_inline_only_when_measured_block_fits` | **VERIFIED FIXED** (P-12-45) | Close row |
| `…SerialCapacityTest::test_ten_or_fewer_serials_use_main_page_only` | **VERIFIED FIXED** (P-12-45) | Close row |
| `…SerialCapacityTest::test_multipage_invoice_keeps_six_serials_on_page_one_without_annexure` | **VERIFIED FIXED** (P-12-45) | Rename → `…_moves_serials_to_annexure_when_product_table_fills_page_one`; update matrix key on import |

Prior recommendation “Remediate PDF logo fixture in tests” was **incorrect**; assets and Imagick were already fine.

---

## 9. Residual risks

- Layout measurement still walks seller header text (including CIN from config); unit capacity tests now require Laravel bootstrap — intentional.
- Other private renderer probes via reflection without `render()` must not assume brand XObjects are loaded unless they call `render()` or only use geometry-only APIs.

---

## 10. Wallet / deploy

- Wallet WIP `d9481615`: **untouched**
- **No** production deploy or customer data copied into repo
- Merge via normal RIP after PR review
