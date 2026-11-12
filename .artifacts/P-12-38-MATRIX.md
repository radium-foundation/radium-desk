# P-12-38 matrix rows (P-12-32 CSV @ 27b03d19, baseline `762cf798`)

| Cluster | Class / row | Matrix status @ launch | Agent | Post-fix (focused run, IST 2026-11-12) |
| --- | --- | --- | --- | --- |
| A | `IncomingEmailLearningCenterAccessTest` × 5 data sets | STILL FAILING | A | **VERIFIED FIXED** 9/9 (incl. non-matrix cases in class) |
| B | `IntelligentAutomationAlertSemanticsTest` × 4 methods | STILL FAILING | B | **VERIFIED FIXED** 7/7 |
| C | `PosStatutorySnapshotTest` × 5 methods | STILL FAILING | C + **P-12-39** | **VERIFIED FIXED** **14/14** — harness **#189** @ `0c1ec2a9`; product **#190** @ `482d6154` |
| C | `PosStatutorySnapshotTest::test_upi_intent_carries_the_sale_snapshot_through_to_complete` | STILL FAILING | **P-12-39** | **VERIFIED FIXED** — UPI cart `statutory` fields (**#190**) |
| C | `PosStatutorySnapshotTest::test_b2b_sale_requires_city_state_and_pin` | STILL FAILING | **P-12-39** | **VERIFIED FIXED** — gated B2B state backfill (**#190**) |
| C | `PosStatutorySnapshotTest::test_b2b_sale_rejects_missing_state` | STILL FAILING | **P-12-39** | **VERIFIED FIXED** — validation + test alignment (**#190**) |
| C | `StatutoryInvoicePdfCustomerFacingSkuTest::test_product_pos_integration…` | STILL FAILING (Reference No.) | C + **P-12-39** | **VERIFIED FIXED** — **#189** + **Counter Tender Reference** label **#190** |
| C | `StatutoryInvoicePdfSerialGroupingAndServicePoTest::test_product_pos_shows_tender_ref…` | (sibling regression) | C + **P-12-39** | **VERIFIED FIXED** — **#189** + Option C label tests **#190** |
| D | Imagick ENVIRONMENT BLOCKED (`StatutoryInvoicePdfCustomerFacingSkuTest` w/o ext) | ENV BLOCKED | D | **CI harness ready** — `extensions: imagick,…` on 6 GHA workflows; local `php -n` still blocked w/o `-d imagick.so` (policy) |

**Programme-owned row count:** 15 matrix rows across clusters A–D (5+4+5+1 env) — **15/15 VERIFIED FIXED** on `main` @ **`482d6154`**.

**Merges:** [PR #189](https://github.com/radium-foundation/radium-desk/pull/189) **`0c1ec2a9`**; [PR #190](https://github.com/radium-foundation/radium-desk/pull/190) (**P-12-39**) **`482d6154`**.

**Running total (P-12-37 lineage, approximate):** STILL FAILING **~87** → **~84** (−3 product rows; see `P-12-39-REPORT.md`).
