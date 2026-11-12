# P-12-38 matrix rows (P-12-32 CSV @ 27b03d19, baseline `762cf798`)

| Cluster | Class / row | Matrix status @ launch | Agent | Post-fix (focused run, IST 2026-11-12) |
| --- | --- | --- | --- | --- |
| A | `IncomingEmailLearningCenterAccessTest` × 5 data sets | STILL FAILING | A | **VERIFIED FIXED** 9/9 (incl. non-matrix cases in class) |
| B | `IntelligentAutomationAlertSemanticsTest` × 4 methods | STILL FAILING | B | **VERIFIED FIXED** 7/7 |
| C | `PosStatutorySnapshotTest` × 5 methods | STILL FAILING | C | **PARTIAL** 11/14 — harness fixes merged **#189** @ `0c1ec2a9`; 3 OPEN product (UPI cart, B2B state backfill ×2) |
| C | `StatutoryInvoicePdfCustomerFacingSkuTest::test_product_pos_integration…` | STILL FAILING (Reference No.) | C | **VERIFIED FIXED** — Owner C (`Tender Ref.` on UNPAID), merged **#189** |
| C | `StatutoryInvoicePdfSerialGroupingAndServicePoTest::test_product_pos_shows_tender_ref…` | (sibling regression) | C | **VERIFIED FIXED** — merged **#189** |
| D | Imagick ENVIRONMENT BLOCKED (`StatutoryInvoicePdfCustomerFacingSkuTest` w/o ext) | ENV BLOCKED | D | **CI harness ready** — `extensions: imagick,…` on 6 GHA workflows; local `php -n` still blocked w/o `-d imagick.so` (policy) |

**Programme-owned row count:** 15 matrix rows across clusters A–D (5+4+5+1 env).

**Merge:** [PR #189](https://github.com/radium-foundation/radium-desk/pull/189) squash **`0c1ec2a9`**.
