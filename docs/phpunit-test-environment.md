# PHPUnit local and CI test environment

Desk PHPUnit assumes a **minimal PHP CLI profile** (no Herd/Laravel Valet `auto_prepend_file`) plus a few **extensions and storage paths**. This document covers the environment blocks called out in P-12-32 / P-12-37 (`ZipArchive` temp dir, Imagick for statutory PDF).

## Standard local command (operator Homebrew PHP 8.5)

```bash
cd /path/to/radium-desk
PHP_INI_SCAN_DIR= /opt/homebrew/bin/php -n vendor/bin/phpunit …
```

| Flag | Purpose |
| --- | --- |
| `PHP_INI_SCAN_DIR=` | Empty scan dir; avoids Herd’s broken or hostile `php.ini` snippets. |
| `php -n` | No default `php.ini`; pairs with project-controlled bootstrap only. |

`phpunit.xml` forces SQLite `:memory:` and testing env keys so a local `.env` cannot override DB or statutory settings (see `docs/rd-fresh-01-pos-finance-gap-audit.md`).

## Storage paths (`ZipArchive` / CA monthly XLSX)

Several tests write under `storage/app/tmp/` (for example `CaMonthlyReportXlsxPackageValidatorTest`). That directory is **not** tracked in git (`storage/app/.gitignore` ignores `*` except `private/` and `public/`).

**Symptom:** `ZipArchive::close(): Failure to create temporary file: No such file or directory`

**Fix (repo):** `tests/bootstrap.php` (referenced from `phpunit.xml`) creates `storage/app/tmp` and `storage/app/tmp/ca-monthly-report` before suites run.

**Fix (manual):** `mkdir -p storage/app/tmp`

**CI:** Fresh checkouts do not include `storage/app/tmp`. The bootstrap runs on every PHPUnit invocation, so GitHub Actions does not need an extra mkdir step.

**Extensions:** `zip` must be enabled. Homebrew PHP 8.5 builds `zip` in core; with `php -n`, `extension_loaded('zip')` is still true. Ubuntu CI images need `extensions: zip` on `shivammathur/setup-php@v2` (explicit is better than implicit).

## Imagick (statutory PDF / `SimplePdfRenderer`)

Brand assets in statutory PDF tests are rasterized via **Imagick** (`app/Services/StatutoryInvoice/SimplePdfRenderer.php`). Without Imagick:

| Test style | PHPUnit outcome |
| --- | --- |
| Direct `SimplePdfRenderer::render()` | **ERROR** (`StatutoryInvoicePdfAssetException`) |
| Integration paths with explicit guard | **SKIPPED** |

### macOS (Homebrew PHP)

ImageMagick is often installed, but **`php -n` does not load PECL `imagick.so`**.

Verify:

```bash
PHP_INI_SCAN_DIR= /opt/homebrew/bin/php -n -r 'var_export(extension_loaded("imagick"));'
# false without extra -d flags
```

Install (when missing):

```bash
brew install imagemagick
pecl install imagick   # links against Homebrew ImageMagick
```

Load for PHPUnit (keep `-n` + empty scan dir; add extension by absolute path):

```bash
IMAGICK_SO="$(/opt/homebrew/bin/php -r 'echo PHP_EXTENSION_DIR;')/imagick.so"
PHP_INI_SCAN_DIR= /opt/homebrew/bin/php -n \
  -d "extension=${IMAGICK_SO}" \
  vendor/bin/phpunit tests/Feature/StatutoryInvoice/StatutoryInvoicePdfCustomerFacingSkuTest.php
```

Avoid relying on Herd’s PHP for CLI PHPUnit; production KVM uses **LiteSpeed `lsphp`** with Imagick enabled (`docs/litespeed-php-infra-audit.md`).

### GitHub Actions

Current workflows (`release-rip-ci.yml`, `statutory-cancellation-phpunit.yml`, etc.) call `setup-php` with **PHP 8.4** and do **not** declare `extensions: imagick`. Statutory PDF classes are mostly **outside** the statutory-cancellation path filter, so CI may not execute them even when green.

**Recommended CI parity** (all PHPUnit workflows):

```yaml
- uses: shivammathur/setup-php@v2
  with:
    php-version: '8.4'
    coverage: none
    tools: composer:v2
    extensions: imagick, zip, gd, sqlite3, mbstring, xml, curl, intl
```

Optionally add a focused job or step for statutory PDF regressions when touching `SimplePdfRenderer` or `StatutoryInvoicePdf*Test.php`.

## Worktrees and shared `vendor/`

Git worktrees often symlink `vendor` to a parent clone. PHPUnit must be invoked with **explicit test file paths** when multiple worktrees exist; suite-wide `--filter` can load duplicate class names from another worktree and fatal with “Cannot redeclare class”.

## Focused reproduction (P-12-37)

```bash
cd .worktrees/p12-37-test-env

PHP_INI_SCAN_DIR= /opt/homebrew/bin/php -n vendor/bin/phpunit \
  tests/Unit/Finance/CaMonthlyReportXlsxPackageValidatorTest.php

IMAGICK_SO="$(/opt/homebrew/bin/php -r 'echo PHP_EXTENSION_DIR;')/imagick.so"
PHP_INI_SCAN_DIR= /opt/homebrew/bin/php -n -d "extension=${IMAGICK_SO}" vendor/bin/phpunit \
  tests/Feature/StatutoryInvoice/StatutoryInvoicePdfCustomerFacingSkuTest.php
```

## Related artifacts

- P-12-32 matrix: `.artifacts/P-12-32-MATRIX-RECONCILIATION.md` (WS-03 Zip, Imagick cluster)
- P-12-37 agent report: `.artifacts/P-12-37-TEST-ENV-REPORT.md`
