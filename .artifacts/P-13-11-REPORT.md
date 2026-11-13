# P-13-11 Report — RBP hardware classification cluster

| Field | Value |
| --- | --- |
| Global Prompt ID | **P-13-11** |
| Ledger ID | **RadiumDesk-P-13-11** |
| Branch | `feature/p13-11-rbp-hardware` |
| Worktree | `.worktrees/p13-11-rbp-hardware` |
| Baseline | `9da7f93863116ce5792ccdf2c7ec9525ea2eec76` (`origin/main` post PR #210) |
| Program baseline | `f358ab04a9a57623085f19c47c6e0045d9b0a7ea` |
| Report started | 2026-11-13 09:25:00 IST |
| Report completed | 2026-11-13 09:36:02 IST |

## Assigned rows (3)

| CSV line | Test method | Status |
| --- | --- | --- |
| 218 | `HardwareFulfilmentPrefixGuardTest::test_rbp_never_opens_hardware_fulfilment_even_with_physical_lines` | **VERIFIED FIXED** |
| 224 | `OrderProductOrderTest::test_non_rin_non_config_prefix_orders_are_not_hardware` | **VERIFIED FIXED** |
| 225 | `OrderProductOrderTest::test_rbp_is_never_hardware_even_if_config_lists_rbp` | **VERIFIED FIXED** |

Shard source: `.artifacts/P-13-07-REPORT.md` § P-13-11.

## Root cause and evidence

| Row(s) | Classification | Evidence label | Summary |
| --- | --- | --- | --- |
| 218, 224, 225 | Application defect (scope split missing) | **VERIFIED** | `BusinessOrderId` marks `RBP*` with `hardware => true`, and both `Order::isHardwareOrderId()` and `HardwareFulfilmentEligibility` treated that flag as “desk hardware queue / isolated fulfilment” without an `RBP` exclusion. RBP IDs were classified as hardware (`true`) where tests expect `false`. |

**Reproduction @ `9da7f938` (pre-fix):** cluster **0/3** — `Failed asserting that true is false.` (~260ms).

## Business rules and policy

| Topic | Finding | Label |
| --- | --- | --- |
| `BusinessOrderId::isHardwareByPrefix('RBP1')` | Remains **true** (RadiumBox product / hardware SKU policy per P-07-09-163) | **PRESERVED** |
| `Order::isHardwareOrderId('RBP*')` | **false** even when `operations.hardware_order_prefixes` lists `RBP` | **CHANGED** (matrix intent) |
| Isolated hardware fulfilment open (`shouldOpenRecord` / `looksLikeHardwareSourceId`) | **false** for `RBP*` (RDE/RIN-style fulfilment only) | **CHANGED** |
| `RDP1` / `RDE*` desk hardware | Still **true** where previously supported | **VERIFIED** preserved |
| Owner doc vs matrix | P-07-09-163 treats RBP as **product** hardware IDs; WS-10 matrix distinguishes **desk fulfilment / ops hardware queue** from prefix metadata. Implemented split without flipping `BusinessOrderId` RBP rule. | **INFERRED** (no Owner escalation) |

**Ambiguity / Owner decision:** None required at commit time — resolution keeps Owner-verified `BusinessOrderId` RBP `hardware=true` and satisfies matrix rows via explicit `RBP` exclusions in `Order` + `HardwareFulfilmentEligibility` only.

## Behaviour changes

| Area | Preserved | Extended | Changed | Removed |
| --- | --- | --- | --- | --- |
| `BusinessOrderId` prefix rules | RBP `hardware=true`; `BusinessOrderIdTest` expectations | — | — | — |
| `Order::isHardwareOrderId` | RIN/RDE/RDP and config fallback for unknown prefixes | Explicit `RBP` → false | Parsed RBP no longer true | — |
| Hardware fulfilment eligibility | RDE commerce-prefix guards; physical-line checks for RDE | Explicit `RBP` never opens fulfilment | `HARDWARE_SOURCE_PREFIXES` = `RDE` only; RBP excluded in parse path | RBP as fulfilment hardware source |

## Tests

**Cluster (3 methods):**

```bash
cd .worktrees/p13-11-rbp-hardware
FILTER='test_rbp_never_opens_hardware_fulfilment_even_with_physical_lines|test_non_rin_non_config_prefix_orders_are_not_hardware|test_rbp_is_never_hardware_even_if_config_lists_rbp'
DESK_PHP_BIN=/opt/homebrew/bin/php ./bin/phpunit-isolated --filter "$FILTER" \
  tests/Unit/HardwareFulfilment/HardwareFulfilmentPrefixGuardTest.php \
  tests/Unit/OrderProductOrderTest.php
```

| Run | Result | Duration |
| --- | --- | --- |
| Cluster | **3/3 PASS** | 251ms |
| Regressions (`HardwareFulfilmentPrefixGuardTest`, `OrderProductOrderTest`, `BusinessOrderIdTest`) | **16/16 PASS** | 565ms |

Full PHPUnit suite: **NOT RUN**.

## Git / CI

| Item | Status |
| --- | --- |
| Commit | _(pending push)_ |
| PR | _(pending)_ |
| CI | _(pending)_ |
| Merge SHA | _(pending)_ |

## Overlap / ownership

No active P-13-12 / P-13-13 fulfilment backfill edits. No canonical matrix CSV edits.

## Production / hardware / deployment

**NO — Not performed** for production access, deployment, hardware operations, inventory changes, or customer device record mutations.

## Wallet preservation

`feature/p18-33-unified-wallet-display` @ **`d94816153cd2c89f8175d29a1510b38b4c16df6b`** — **VERIFIED** unchanged.

## Stage durations

| Stage | Duration |
| --- | --- |
| Worktree + prechecks | ~4 min |
| Repro + policy trace | ~8 min |
| Fix + validation | ~3 min |
| Report + git gates | ~2 min |
