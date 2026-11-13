# P-13-23 — Inventory POS operational workflow (matrix row 91)

| Field | Value |
| --- | --- |
| Global Prompt ID | **P-13-23** |
| Cursor ledger ID | **RadiumDesk-P-13-23** |
| Repository | `/Users/ravi/RadiumWebsites/radium-desk` |
| Remote | `git@github.com:radium-foundation/radium-desk.git` |
| Worktree | `.worktrees/p13-23-inventory-pos` → `feature/p13-23-inventory-pos` |
| Verified `origin/main` (start) | **`870afab75f44d5f99234bf514faddefaa98c59a4`** |
| Wallet WIP (unchanged) | **`d94816153cd2c89f8175d29a1510b38b4c16df6b`** |
| Start | **2026-11-13 11:57:00 IST** |
| End | **2026-11-13 12:15:00 IST** (approx.) |

---

## Exact assignment

| Field | Value |
| --- | --- |
| Line | **91** |
| Method | `Tests\Feature\Inventory\InventoryPosOperationalWorkflowTest::test_operator_workflow_catalog_serial_lifecycle_sale_invoice_and_finance` |
| Focused regression | Full `InventoryPosOperationalWorkflowTest` class smoke |
| Dependencies | P-13-08 statutory issuance — closed, read-only; P-13-11 hardware — closed, read-only |

**Canonical register not edited.**

---

## Journey inspection (catalog → finance)

| Stage | Finding | Label |
| --- | --- | --- |
| Catalog / product CRUD | POST `inventory.products.store` for serialized + variant products | **VERIFIED** PASS (pre-Vite gate) |
| Serial intake / dup guard | Stock-in serials; duplicate serial rejected | **VERIFIED** PASS |
| Reservation lifecycle | `InventoryStockService` reserve/release | **VERIFIED** PASS |
| Inter-branch transfer | Serial `QA-SN-002` moves to branch B | **VERIFIED** PASS |
| Cross-branch sale guard | Sale of transferred serial from branch A → session errors | **VERIFIED** PASS |
| Counter UI GET | `pos.counter.create` Blade | **TEST_SETUP** — `ViteManifestNotFoundException` without harness |
| Product/search JSON APIs | `pos.products.search`, `pos.serials.search` | **VERIFIED** PASS (after harness) |
| Sale completion | `pos.counter.store` → sale, stock, serial sold | **VERIFIED** PASS |
| Amounts | subtotal/discount/tax unchanged; total uses nearest-rupee round-off | **VERIFIED** APP since `InvoiceRoundOff` in `PosSaleService` |
| Finance journal | Posted at rounded `sale.total` | **VERIFIED** PASS |
| Invoice / receipt GET | `pos.sales.invoice` internal receipt | **VERIFIED** PASS |
| Idempotency | Duplicate `idempotency_key` redirects, single sale | **VERIFIED** PASS |
| Cancel authorization | Hardware role forbidden; admin cancel in sibling tests | **VERIFIED** PASS |

**No application defect found in sale engine for assigned journey** after harness alignment. **TEST** expectations for header total were stale vs nearest-rupee POS totals.

---

## Root cause classification

| Issue | Class | Label |
| --- | --- | --- |
| HTTP 500 on counter create | **TEST_SETUP** — missing `public/build/manifest.json` in worktree/isolated PHPUnit; production ships built assets | **VERIFIED** |
| Total `3034.0` vs expected `3034.40` | **TEST** — expectations pre-date `InvoiceRoundOff::nearestRupee()` on POS completion (`6167b793`); unrounded 3034.40 → rounded 3034.00 | **VERIFIED** |

---

## Vite / environment

| Item | Verdict |
| --- | --- |
| `withoutVite()` in `setUp` | Aligns with `InventoryPosAuthorizationTest`, `PosStatutorySnapshotTest`, and ~80+ feature tests — stubs asset manifest for server-side journey tests without asserting frontend bundle integration |
| Hides frontend defect? | **No** — failure was manifest absence in test env, not Blade/route regression |

---

## High-risk coverage

| Requirement | Result |
| --- | --- |
| Assigned catalog→finance method | **PASS** (62 assertions, **4274 ms**) |
| Full workflow class (5 tests) | **PASS** (97 assertions, **8158 ms**) |
| Neighbour `InventoryPosAuthorizationTest` + `PosStatutorySnapshotTest` | **PASS** (19 tests, **21438 ms**) |
| `InventoryPosMysqlConcurrencyTest` (MySQL twin-sale) | **NOT RUN** — requires gated local MySQL bootstrap; unchanged by this diff |
| Statutory auto-issue on POS | **Not exercised** — policy remains read-only vs P-13-08 |
| Full PHPUnit suite | **NOT RUN** |

---

## Sequential steps

| Step | Action | Result |
| ---: | --- | --- |
| 1 | Verify P-13-22 closed; worktree @ `870afab7`; reproduce row 91 | Vite 500 @ line 157 |
| 2 | Classify Vite vs application | **TEST_SETUP** |
| 3 | Add `withoutVite()` | Expose total round-off mismatch |
| 4 | Align test totals with `InvoiceRoundOff` (no `PosSaleService` change) | **PASS** assigned + class |
| 5 | Ledger, PR, CI, merge | See below |

---

## Behaviour

| | |
| --- | --- |
| **Preserved** | `PosSaleService`, finance posting, statutory issuance boundaries, serial/stock rules |
| **Extended** | Test harness parity for POS Blade GETs |
| **Changed** | Test expectations: `sale.total` and journal totals **3034.00** |
| **Removed** | Nothing |

---

## Focused validation commands

```bash
cd .worktrees/p13-23-inventory-pos
DESK_PHP_BIN=/opt/homebrew/bin/php ./bin/phpunit-isolated \
  --filter test_operator_workflow_catalog_serial_lifecycle_sale_invoice_and_finance \
  tests/Feature/Inventory/InventoryPosOperationalWorkflowTest.php
# exit 0 — 1/1 PASS, 62 assertions, ~4274 ms

DESK_PHP_BIN=/opt/homebrew/bin/php ./bin/phpunit-isolated \
  tests/Feature/Inventory/InventoryPosOperationalWorkflowTest.php
# exit 0 — 5/5 PASS, 97 assertions, ~8158 ms
```

---

## Git / PR / CI

| Field | Value |
| --- | --- |
| Branch | `feature/p13-23-inventory-pos` |
| Starting HEAD | `870afab75f44d5f99234bf514faddefaa98c59a4` |
| Feature commit / merge | *(filled after merge)* |
| PR | *(filled after create)* |

**Production:** **NO** — not performed.

---

## Remaining risks

- `tests/Feature/Inventory/Support/inventory_pos_browser_qa.mjs` still references unrounded **3034.40** in UI quote copy (**INFERRED** possible E2E drift vs rounded persisted sale total; out of row 91 scope).
- MySQL concurrency journey not re-run in this prompt.
- Matrix row 91 register status unchanged until CSV reconciliation.

---

*End of P-13-23 report.*
