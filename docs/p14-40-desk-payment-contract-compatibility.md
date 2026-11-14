# P-14-40 — Desk payment contract compatibility (Desk side)

**Global ID:** P-14-40  
**Cursor ledger:** **RadiumDesk-P-14-40**  
**Repository:** `radium-desk`  
**Branch:** `feature/p14-38-desk-sole-payment-authority`  
**Base commit (pre-P-14-40):** `918e026a2a6605fcbb16460829c93b783b4f719f`  
**Report timezone:** IST (UTC+5:30)  
**Stage duration (this task):** ~35m (2026-11-14 13:05–13:40 IST, measured)

## Canonical contract version

| Layer | Value |
|--------|--------|
| JSON `contract_version` (wire + Box validation) | **`desk_authoritative_payment_v1`** |
| Desk env `RADIUMBOX_PAYMENT_CONFIRM_CONTRACT` (gateway selector) | **`desk_authoritative_v1`** |

No ambiguous dual JSON versions; Box rejects any other `contract_version`.

## HMAC compatibility (evidence)

**Desk (sign):** `DeskAuthoritativePaymentEventSigner::signBody($transmitJson, $secret)` → `sha256=` + `hash_hmac('sha256', $transmitJson, trim($secret))`.

**Desk (send):** `DeskAuthoritativeRadiumBoxPaymentGateway` posts **`withBody($transmitJson, 'application/json')`** where `$transmitJson = $event->transmitJson($dryRun)` — same bytes as signed.

**Box (verify):** `DeskAuthoritativePaymentSignatureVerifier` on **`$request->getContent()`** with `hash_equals`.

**Shared golden vector:** `tests/fixtures/desk-authoritative-payment-v1-golden.json` (byte-identical copy in radiumbox.com).

- Golden body HMAC: `sha256=9f5571966a00fe5963d7c8d29d1ea83202d12bef484dc2d6ab3918cb6254c78b`
- Box feature test posts golden bytes → **accepted**
- Desk contract test asserts outbound HTTP body re-signs to matching HMAC
- Pretty-print wire bytes with compact signature → **403** (Box)

## Tests (Desk)

| Suite | Result |
|--------|--------|
| `DeskAuthoritativePaymentV1GoldenContractTest` | **PASSED** (2) |
| `RadiumBoxAuthoritativePaymentContractTest` | **PASSED** (3) |
| `RadiumBoxPaymentConfirmationOutboxTest` | **PASSED** (3) |
| MySQL concurrent duplicate `desk_payment_events` | **NOT RUN** (no harness; policy gate) |
| Full PHPUnit suite | **NOT RUN** |

PHP: `/opt/homebrew/bin/php` with `PHP_INI_SCAN_DIR=` (Herd extension unavailable).

## Changes (P-14-40 scope)

| | |
|--|--|
| **Preserved** | Legacy `legacy_cashfree_via_box` default; wallet WIP unstaged |
| **Extended** | `transmitJson()`, `signBody()`, golden fixture + contract tests |
| **Changed** | Gateway HTTP body = signed bytes; spec clarifies version naming |
| **Removed** | None |

## Git

Payment-contract files modified **uncommitted** on branch (large unrelated dirty tree preserved).

**Production:** **NO** — deploy, DB, integration flags, push, PR.

## Remaining risks

1. MySQL duplicate-delivery concurrency not exercised under InnoDB unique indexes (`event_id`, `idempotency_key`).
2. P-14-40 Desk fix must be committed and paired with Box branch before release.
3. Signing secret rotation procedure not automated (shared env config only).

**Release readiness:** Cross-repo golden interop **proven**; **not** declared production-ready until MySQL concurrency + full-suite gates per `docs/RADIUMWEBSITES_TESTING_AND_VALIDATION_POLICY.md`.