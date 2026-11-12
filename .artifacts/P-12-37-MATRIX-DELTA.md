# P-12-37 — Matrix delta (post Agent 4 @ `af239eec`)

Authoritative baseline remains **P-12-32** @ `27b03d19` / CSV in this folder. This file records **Agent 4** status changes only (do not rewrite historical P-12-32 rows).

| Matrix method (stable key) | WS | Prior status | Post Agent 4 | Evidence / next owner |
| --- | --- | --- | --- | --- |
| `Tests.Unit.Finance.CaMonthlyReportXlsxPackageValidatorTest::test_validator_flags_minimal_package_missing_excel_required_parts` | WS-03 | ENVIRONMENT BLOCKED | **VERIFIED FIXED** | Harness: `tests/bootstrap.php` + `phpunit.xml`; focused run exit **0** |
| `Tests.Feature.StatutoryInvoice.StatutoryInvoicePdfCustomerFacingSkuTest::test_product_pos_integration_pdf_omits_sku_but_preserves_financial_columns` | WS-10 | ENVIRONMENT BLOCKED | **STILL FAILING** | Under `php -n` → SKIP (no Imagick). With Imagick → **FAIL** (`Reference No.` — non-env). **Not** Agent 4 |

## Class-level notes (not separate P-12-32 matrix rows)

| Class | Issue | Status @ Agent 4 |
| --- | --- | --- |
| `StatutoryInvoicePdfCustomerFacingSkuTest` (3 direct renderer tests) | Imagick missing under mandated `PHP_INI_SCAN_DIR= php -n` | **ENVIRONMENT BLOCKED** (local/CI extension parity — doc + recommended CI `imagick`) |
| Same (integration method) | See matrix row above | **STILL FAILING** when env unblocked |

## Count impact (P-12-37 accounting @ `fafec714` baseline)

| Metric | Before coordinator launch | After Agent 4 |
| --- | ---: | ---: |
| ENVIRONMENT BLOCKED (matrix rows) | 2 | **1** (Imagick / `-n` profile; zip row fixed) |
| VERIFIED FIXED (incremental) | 0 | **+1** (CaMonthlyReport validator) |
| STILL FAILING (reclassified from ENV) | — | **+1** (statutory integration PDF assertion) |
| Agent 4 matrix rows **completed** | 0/2 | **1/2** fixed; **1/2** reclassified out of ENV |

## Imagick CI recommendation (coordinator)

1. Add `imagick` (and explicit `zip`, etc.) to every GHA `setup-php` step that runs PHPUnit.
2. Optionally extend path filters or add a job for `tests/Feature/StatutoryInvoice/StatutoryInvoicePdf*Test.php`.
3. No extra mkdir in CI after bootstrap merge.
