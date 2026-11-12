# P-12-37 — Test environment (Agent 4)

| Field | Value |
| --- | --- |
| Worktree | `.worktrees/p12-37-test-env` |
| Branch | `feature/p12-37-test-env` |
| Baseline context | P-12-32 matrix ENVIRONMENT BLOCKED rows (WS-03) |

## Classification summary

| Test class | Issue | Classification | After P-12-37 |
| --- | --- | --- | --- |
| `CaMonthlyReportXlsxPackageValidatorTest` | Missing `storage/app/tmp` → `ZipArchive::close` error | **FIXED** (harness) | Exit **0**, 1/1 pass |
| `StatutoryInvoicePdfCustomerFacingSkuTest` | `php -n` omits PECL Imagick | **BLOCKED** (local CLI profile) | Exit **2**: 3 ERROR, 1 SKIP |
| `StatutoryInvoicePdfCustomerFacingSkuTest` | With `-d extension=…/imagick.so` | Env **unblocked** | Exit **1**: 3 pass, 1 **FAIL** (assertion: missing `Reference No.` — app/test expectation, not extension) |

## Root causes

### ZipArchive

- Test writes to `storage_path('app/tmp/…')` but `storage/app/tmp` is gitignored and absent on fresh worktrees.
- `zip` extension is present under `php -n` on Homebrew 8.5; failure was **parent directory missing**, not missing `ext-zip`.

### Imagick

- PECL `imagick.so` is installed at `/opt/homebrew/lib/php/pecl/20250925/imagick.so` on this host but **not loaded** when using `PHP_INI_SCAN_DIR= php -n`.
- Three methods call `SimplePdfRenderer` without skip guards → hard **ERROR**.
- Integration method has `markTestSkipped` when Imagick missing → **SKIP** under `-n`.

### CI parity gap

- GitHub Actions `setup-php@v2` jobs do not list `extensions: imagick`.
- Statutory PDF test files are largely **outside** `statutory-cancellation-phpunit.yml` path filters, so CI does not routinely execute this class.

## Changes (harness + docs only)

| Path | Change |
| --- | --- |
| `tests/bootstrap.php` | Create `storage/app/tmp` (+ `ca-monthly-report` subdir) before suites |
| `phpunit.xml` | `bootstrap="tests/bootstrap.php"` |
| `docs/phpunit-test-environment.md` | Reproducible local + CI setup |

No test assertions weakened; no app code changed.

## Focused PHPUnit (`PHP_INI_SCAN_DIR= /opt/homebrew/bin/php -n vendor/bin/phpunit`)

### Zip validator

```
tests/Unit/Finance/CaMonthlyReportXlsxPackageValidatorTest.php
→ result: passed | exit: 0
```

Log captured: `.artifacts/phpunit-p12-37-zip.log` (see git commit / local run).

### Customer-facing SKU (default `-n`, no Imagick)

```
tests/Feature/StatutoryInvoice/StatutoryInvoicePdfCustomerFacingSkuTest.php
→ result: failed | tests: 4 | errors: 3 | skipped: 1 | exit: 2
```

### Customer-facing SKU (Imagick loaded)

```
PHP_INI_SCAN_DIR= /opt/homebrew/bin/php -n -d extension=/opt/homebrew/lib/php/pecl/20250925/imagick.so …
→ result: failed | passed: 3 | failed: 1 | exit: 1
```

Failure: `test_product_pos_integration_pdf_omits_sku_but_preserves_financial_columns` — not classified as environment.

## Recommended CI changes

1. Add `extensions: imagick, zip, gd, sqlite3, mbstring, xml, curl, intl` to every `shivammathur/setup-php@v2` step running PHPUnit.
2. Consider path filters or a dedicated job for `tests/Feature/StatutoryInvoice/StatutoryInvoicePdf*Test.php` when PDF renderer changes.
3. No workflow change required for Zip temp dirs after `tests/bootstrap.php` merges.

## Operator notes

- Use **explicit test file paths** in worktrees (shared `vendor` symlink can cause “Cannot redeclare class” with suite-wide discovery + `--filter`).
- Documented Imagick one-liner: `docs/phpunit-test-environment.md`.
