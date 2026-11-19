# P-19-63 — MariaDB B2C conversion concurrency execution

**ChatGPT ID:** P-19-63  
**Cursor ledger ID:** `bd0ac5bd-88f0-48e0-aacd-66eca9be9aef`  
**Desk ledger row:** `RadiumDesk-P-19-63`  
**Start:** 2026-11-19T20:32:00+05:30 (approx.)  
**End:** 2026-11-19T20:42:00+05:30 (approx.)  
**Measured concurrency test duration:** **125s** (exit **0**)

## VERIFIED

- MariaDB **11.8.8-MariaDB** on **`127.0.0.1:33118`** (loopback; hostname `Ravis-MacBook-Air`).
- Disposable database **`radium_desk_buyer_gst_b2c_conversion_concurrency_test`** only; scoped user **`rd_buyer_gst_b2c_conversion_test`@`127.0.0.1`** (not `radium_desk`, not port **3306**).
- **`BuyerGstB2cConversionMysqlConcurrencyTest`** **2/2 PASS**, **42** assertions, after harness + migration + concurrency fixes.
- Final DB after successful run (iteration **c2** post-wipe): **1** `commerce_order_buyer_gst_b2c_conversions` row (`approved`, `submitted_gstin` **07AAICB5215F2ZX**); verification row retains `provider_payload_hash` + `inactive_registration`; **0** `statutory_invoices`.
- Independent worker `connection_id` values asserted in test (not equal between workers).

## INFERRED

- Concurrent dual `approve()` required **commerce_orders** row `lockForUpdate` + transaction **5** attempts + unique-constraint replay to avoid InnoDB **1213** on parallel insert.

## UNKNOWN

- CI disposable MariaDB availability (local-only proof this session).

## Risk

**HIGH** — statutory B2C classification under concurrent Finance approval.

## Isolation / cleanup evidence

| Check | Result |
| --- | --- |
| Host allowlist gate test | PASS |
| Database name gate | PASS |
| Production `radium_desk` on 3306 | **Not used** |
| Prepare uses `db:wipe` on disposable DB only | VERIFIED per prepare script + gate |
| Homebrew MySQL 3306 | Untouched |

**Provision commands (root on 33118 only):**

```sql
CREATE DATABASE radium_desk_buyer_gst_b2c_conversion_concurrency_test;
CREATE USER 'rd_buyer_gst_b2c_conversion_test'@'127.0.0.1' ...;
GRANT ALL ON radium_desk_buyer_gst_b2c_conversion_concurrency_test.* TO ...;
```

## Test execution

```bash
export BUYER_GST_B2C_CONVERSION_MYSQL_HOST=127.0.0.1
export BUYER_GST_B2C_CONVERSION_MYSQL_PORT=33118
export BUYER_GST_B2C_CONVERSION_MYSQL_DATABASE=radium_desk_buyer_gst_b2c_conversion_concurrency_test
export BUYER_GST_B2C_CONVERSION_MYSQL_USERNAME=rd_buyer_gst_b2c_conversion_test
export BUYER_GST_B2C_CONVERSION_MYSQL_PASSWORD=rd_buyer_gst_b2c_conversion_test_pw
export APP_ENV=testing

phpunit tests/Feature/StatutoryInvoice/BuyerGstB2cConversionMysqlConcurrencyTest.php
```

| Result | Tests | Assertions | Exit | Duration |
| --- | --- | --- | --- | --- |
| **PASSED** | 2/2 | 42 | 0 | **~125s** |

**Iterations:** Harness runs **2** deterministic repetitions (`suffix=c1`, `c2`); each prepare wipes disposable DB, migrates, seeds order + verification, then **2** workers barrier-synced approve concurrently.

**Worker sync:** ready-file barrier → shared `go` file → `Process::start()` + `wait()`.

**Focused SQLite regressions (post-fix):**

```bash
phpunit tests/Feature/StatutoryInvoice/CommerceOrderBuyerGstB2cFallbackTest.php \
  tests/Feature/StatutoryInvoice/CommerceOrderBuyerGstVerificationTest.php
```

**22/22 PASS**, exit **0**, ~**2.5s**.

**Pint:** PASS on dirty files.

## Engineering fixes (this prompt)

| Change | Reason |
| --- | --- |
| Bootstrap `require_once` Support gate/lock classes | Subprocess autoload when `vendor` not co-located with worktree tests |
| MariaDB-short FK/index names on GST migrations | Identifier >64 chars blocked migrate on MariaDB 11.8.8 |
| `BuyerGstB2cConversionMysqlConcurrencyTest` use `wait()` not `mustRun()` | Workers already `start()`ed |
| `CommerceOrderBuyerGstB2cConversionService::approve` order lock + txn retries + unique replay | Deadlock **1213** / race on parallel insert |

## Transaction / locking / idempotency

- `commerce_orders` **`lockForUpdate`** before conversion row lock.
- `commerce_order_buyer_gst_b2c_conversions.commerce_order_id` **unique** (`co_b2c_conv_order_uq`).
- Second concurrent worker: same `conversion_id`, `conversion_count=1`, `buyer_gstin_for_mint_is_null=true`, `issue_as_b2b=false`.
- Idempotent re-approve preserves first `decision_reason` (covered in SQLite suite).

## NO — Not performed

Production migration · feature flags · deployment · backup · WhiteBooks production · invoice **23163** / **RBP2295** · financial/customer prod changes

## Readiness statement

MariaDB concurrency **PASS** closes the **engineering concurrency gap** only. **Does not** authorize production migration, GST verification activation, B2C fallback activation, or deployment. CA/Finance matrix and protected-journey UAT remain **separate**.