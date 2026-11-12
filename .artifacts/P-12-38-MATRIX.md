# P-12-38 matrix rows (P-12-32 CSV @ 27b03d19, baseline `762cf798`)

| Cluster | Class / row | Matrix status @ launch | Agent | Post-fix (focused run, IST 2026-11-12) |
| --- | --- | --- | --- | --- |
| A | `IncomingEmailLearningCenterAccessTest` × 5 data sets | STILL FAILING | A | **VERIFIED FIXED** 9/9 (incl. non-matrix cases in class) |
| B | `IntelligentAutomationAlertSemanticsTest` × 4 methods | STILL FAILING | B | **VERIFIED FIXED** 7/7 |
| C | `PosStatutorySnapshotTest` × 5 methods | STILL FAILING | C | **PARTIAL** 9/14 — harness `withoutVite()` on `feature/p12-38-pos-statutory-reference` @ `0c298d8a` (no PR); 4 FAIL + 1 ERR (B2B / show / UPI paths) |
| C | `StatutoryInvoicePdfCustomerFacingSkuTest::test_product_pos_integration…` | STILL FAILING (Reference No.) | C | **STOP — Owner** — see `P-12-38-AGENT-C-STOP.md` (UNPAID omits Reference No.; test expects label + `UPI-SKU-TEST`) |
| D | Imagick ENVIRONMENT BLOCKED (`StatutoryInvoicePdfCustomerFacingSkuTest` w/o ext) | ENV BLOCKED | D | **CI harness ready** — `extensions: imagick,…` on 6 GHA workflows; local `php -n` still blocked w/o `-d imagick.so` (policy) |

**Programme-owned row count:** 15 matrix rows across clusters A–D (5+4+5+1 env).
