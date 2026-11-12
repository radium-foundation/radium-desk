# P-12-39 — POS statutory tender-reference (Option C) + matrix reconcile

| Field | Value |
| --- | --- |
| Prompt | **`RadiumDesk-P-12-39`** (claimed in `docs/cursor-prompt-ledger.md` on impl branch) |
| Repository | `radium-foundation/radium-desk` |
| Implementation worktree | `.worktrees/p12-39-pos-statutory-option-c` |
| Coordinator reconcile | `.worktrees/p12-38-coordinator` → `feature/p12-38-coordinator` |
| Base (impl) | **`origin/main`** @ **`0c1ec2a9`** (#189) |
| Merge close | **`origin/main`** @ **`482d615484e080c358eb9cf28818901f6b0cf9cf`** ([#190](https://github.com/radium-foundation/radium-desk/pull/190)) |
| Subagent evidence | Cursor `b57af62e-f45c-444d-bb45-ae3a3431e2dc` |
| Wallet protected | `feature/p18-33-unified-wallet-display` @ **`d9481615`** — **not modified** |
| Full suite | **NO** |
| Deploy | **NO** |

## Root cause

| Area | Cause |
| --- | --- |
| **PDF label** | P-12-38 used `Tender Ref.`; Owner Option C requires **Counter Tender Reference** for UNPAID + tender ref. |
| **UPI snapshot** | `PosUpiIntentService` did not copy `billing_city` / `billing_state` / `billing_pincode` into `cart_payload.statutory`. |
| **B2B matrix** | `CounterController` backfilled `billing_state` from `place_of_supply` on GSTIN alone, suppressing `billing_state` validation; `test_b2b_sale_rejects_missing_state` included `place_of_supply_state` unlike `PosB2bCustomerAddressTest`. |

## PDF behaviour (Option C)

1. **UNPAID + tender ref** → label **Counter Tender Reference** (not receipt/allocation)
2. **UNPAID + no tender ref** → no reference line
3. **PAID/allocated** → **Reference No.** from allocation-backed evidence (renderer logic unchanged; label split only)

Constant: `SimplePdfRenderer::PAYMENT_TENDER_REFERENCE_LABEL = 'Counter Tender Reference'`

## Three matrix row outcomes

| Test | Matrix status @ `482d6154` |
| --- | --- |
| `test_upi_intent_carries_the_sale_snapshot_through_to_complete` | **VERIFIED FIXED** |
| `test_b2b_sale_requires_city_state_and_pin` | **VERIFIED FIXED** |
| `test_b2b_sale_rejects_missing_state` | **VERIFIED FIXED** |

Subagent focused evidence (local, IST ~21:14–21:18, 2026-11-12): `PosStatutorySnapshotTest` **14/14 PASS**; no full-suite re-run at coordinator reconcile.

## Test commands / exits (implementation agent)

```bash
PHP_INI_SCAN_DIR= /opt/homebrew/bin/php -n vendor/bin/phpunit tests/Feature/Inventory/PosStatutorySnapshotTest.php
# 14/14 PASS, exit 0

PHP_INI_SCAN_DIR= /opt/homebrew/bin/php -n -d extension=/opt/homebrew/lib/php/pecl/20250925/imagick.so \
  vendor/bin/phpunit tests/Feature/StatutoryInvoice/StatutoryInvoicePdfSerialGroupingAndServicePoTest.php --filter test_product_pos
# 3/3 PASS, exit 0

PHP_INI_SCAN_DIR= /opt/homebrew/bin/php -n -d extension=/opt/homebrew/lib/php/pecl/20250925/imagick.so \
  vendor/bin/phpunit tests/Feature/StatutoryInvoice/StatutoryInvoicePdfCustomerFacingSkuTest.php --filter test_product_pos_integration
# 1/1 PASS, exit 0
```

| Gate | Status |
| --- | --- |
| Full PHPUnit | **NO** |
| Deploy | **NO** |
| Imagick (local `php -n`) | **BLOCKED** without `-d extension=imagick.so` for PDF render tests |

## PR / merge / RIP

| Item | Value |
| --- | --- |
| **PR** | [#190](https://github.com/radium-foundation/radium-desk/pull/190) — squash-merged |
| **Merge SHA** | `482d615484e080c358eb9cf28818901f6b0cf9cf` |
| Pre-merge CI (head `2ead5be9`) | **rip** [43251563440](https://github.com/radium-foundation/radium-desk/actions/runs/43251563440) + **release-rip** [43251563075](https://github.com/radium-foundation/radium-desk/actions/runs/43251563075) — **SUCCESS** |
| Post-merge CI (`482d6154`) | **rip** [43251780484](https://github.com/radium-foundation/radium-desk/actions/runs/43251780484) + **release-rip** [43251780596](https://github.com/radium-foundation/radium-desk/actions/runs/43251780596) — **SUCCESS** |

## Wallet (coordinator verify)

| Check | Value |
| --- | --- |
| Branch | `feature/p18-33-unified-wallet-display` |
| `git rev-parse d9481615` | `d94816153cd2c89f8175d29a1510b38b4c16df6b` |
| Action | **None** |

## Matrix update (P-12-32 CSV + P-12-38 programme slice)

| Metric | @ P-12-38 close (`0c1ec2a9`) | @ P-12-39 reconcile (`482d6154`) |
| --- | ---: | ---: |
| P-12-38 slice **STILL FAILING** (product) | **3** PosStatutory rows | **0** |
| P-12-37 lineage **STILL FAILING** (approx.) | **~87** | **~84** (−3) |
| Cumulative **VERIFIED FIXED** (approx.) | **~139** | **~142** (+3) |

CSV rows updated in `P-12-32-matrix-reconciliation.csv` (lines 92–93, 96).

## Code touch (presentation + snapshot only)

- `SimplePdfRenderer.php` — label
- `PosUpiIntentService.php` — cart statutory fields
- `CounterController.php` — gated B2B state backfill
- Tests + ledger row (on `main` via #190)
