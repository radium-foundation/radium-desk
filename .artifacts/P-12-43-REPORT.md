# P-12-43 — Imagick CI / local `php -n` parity

| Field | Value |
| --- | --- |
| Prompt | P-12-43 |
| Branch | `feature/p12-43-imagick-phpunit-parity` |
| Baseline | `origin/main` @ `9f87dff9` (includes #186 CI Imagick, #190 P-12-39 PDF) |

## Diagnosis

| Layer | Finding |
| --- | --- |
| **CI** | **RESOLVED on main** — PR **#186** adds `extensions: imagick, zip, gd, sqlite3, mbstring, xml, curl, intl` to all six PHPUnit workflows. |
| **Local `php -n`** | Built-in extensions (zip, gd, mbstring, …) load; **PECL Imagick does not** without `-d extension=/path/imagick.so`. |
| **Bootstrap** | Cannot `dl()` Imagick at runtime under PHP 8+ CLI; extension must be loaded before PHPUnit starts. |
| **Homebrew path** | PECL module at `/opt/homebrew/lib/php/pecl/20250925/imagick.so` (API dir), not `PHP_EXTENSION_DIR/imagick.so`. |
| **Imagick version (local)** | ImageMagick **7.1.2-31** via PECL when explicitly loaded |

## Changes

| Path | Change |
| --- | --- |
| `bin/phpunit-isolated` | `php -n` + empty `PHP_INI_SCAN_DIR`; autoload Imagick `.so` when present |
| `docs/phpunit-test-environment.md` | Document wrapper, PECL path resolution, CI #186 status |

No application or statutory calculation changes. No test skips weakened.

## Focused PHPUnit (`./bin/phpunit-isolated`)

| Target | Result | Duration |
| --- | --- | ---: |
| `StatutoryInvoicePdfCustomerFacingSkuTest` | **4/4 PASS** | ~6.2s |
| `StatutoryInvoicePdfPresentationTest` (`--filter` logo cluster) | **6/6 PASS** | ~8.1s |
| Same SKU class, raw `php -n` (no `-d extension`) | **3 ERROR**, 1 SKIP (expected) | ~4.9s |

Full suite: **NOT RUN** (policy).

## CI matrix (existing, verified on main)

Workflows: `release-rip-ci`, `statutory-cancellation-phpunit`, `availability-sync-rip`, `driver-management-rip`, `hardware-shipment-documents-rip`, `hardware-sku-map-rip` — all declare Imagick via `setup-php@v2`.

Post-merge **#191** (P-12-40): Release RIP + Availability sync RIP **SUCCESS** @ `9f87dff9`.

## Residual risks

- Operators without PECL Imagick installed still see ERROR/SKIP on unguarded PDF unit paths; install via `brew install imagemagick && pecl install imagick` or set `DESK_IMAGICK_SO`.
- `bin/phpunit-safe` (P-12-40) does not invoke `php -n`; use `bin/phpunit-isolated` for mandated isolation profile.
- Statutory PDF classes outside path-filtered CI jobs still rely on release-RIP or manual focused runs.

## Wallet / prod

Wallet WIP **`d9481615`** untouched. **NO** deploy / production access.
