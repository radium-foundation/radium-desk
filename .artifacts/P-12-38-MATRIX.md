# P-12-38 matrix rows (P-12-32 CSV @ 27b03d19, baseline `762cf798`)

| Cluster | Class / row | Matrix status @ launch | Agent | Post-fix (focused run, IST 2026-11-12) |
| --- | --- | --- | --- | --- |
| A | `IncomingEmailLearningCenterAccessTest` × 5 data sets | STILL FAILING | A | **VERIFIED FIXED** 9/9 (incl. non-matrix cases in class) |
| B | `IntelligentAutomationAlertSemanticsTest` × 4 methods | STILL FAILING | B | **VERIFIED FIXED** 7/7 |
| C | `PosStatutorySnapshotTest` × 5 methods | STILL FAILING | C | **PARTIAL** 10/14 — 4 FAIL (B2B session validation + show/reprint/UPI paths); 1 ERR |
| C | `StatutoryInvoicePdfCustomerFacingSkuTest::test_product_pos_integration…` | STILL FAILING (Reference No.) | C | **STOP — Owner** (UNPAID omits Reference No.; test expects label + `UPI-SKU-TEST`) |
| D | Imagick ENVIRONMENT BLOCKED (`StatutoryInvoicePdfCustomerFacingSkuTest` w/o ext) | ENV BLOCKED | D | **CI harness ready** — `extensions: imagick,…` on 6 GHA workflows; local `php -n` still blocked w/o `-d imagick.so` (policy) |

**Programme-owned row count:** 15 matrix rows across clusters A–D (5+4+5+1 env).
