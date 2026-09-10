# Production WhiteBooks GSP IP configuration — P-07-09-200

**Project:** Radium Desk  
**Prompt ID:** RadiumDesk-P-07-09-200  
**Date:** 2026-09-10  
**HEAD before:** `6bf2279c720b431259aac49aceea857439b4d435`  
**Repository:** `/Users/ravi/RadiumWebsites/radium-desk-irn-foundation`  
**Branch:** `feat/irn-foundation-phase-a`  
**Production:** KVM8 `srv1910783` / `187.127.129.16` `/var/www/radium-desk` DB `radium_desk`

**Mechanism:** existing `.env` + `config/statutory_invoices.php` `env('STATUTORY_EINVOICE_GSP_IP_ADDRESS')`. Named-file overlay of `WhitebooksCredentialResolver.php` (IPv4 fail-closed). **Not** `deskd`. No migrate. Provider remains `none`.

## Why

P-199 recovery only worked because `187.127.129.16` was set **in-process**. Production `.env` did not have `STATUTORY_EINVOICE_GSP_IP_ADDRESS` (KEY_ABSENT). Application config already mapped the key; missing IP already failed closed.

## Code

- No production IP in PHP source.
- Tests use documentation IPv4 (`203.0.113.10`, `198.51.100.20`), not the production address.
- Missing/blank IP → `missing_gsp_ip_address`, no HTTP.
- Non-IPv4 (`localhost`, malformed, incomplete, IPv6) → `invalid_gsp_ip_address`, no HTTP.
- No `request()->ip()`, localhost, `192.168.0.1`, or discovery fallback.

## Production configuration

Backup: `/var/www/radium-desk/storage/app/private/overlays/p-07-09-200-20260910T111501Z`

| Item | Result |
|------|--------|
| `.env` IP key before | KEY_ABSENT |
| `.env` IP key after | PRESENT, matches `187.127.129.16` |
| `.env` mode | `600 ravi:ravi` |
| Provider | still `none` |
| Resolver overlay | SHA-256 `1aea8059…` MATCH local |
| `artisan optimize:clear` + `optimize` | yes |
| In-process IP override | **NO** |

Credentials were not printed. `.env` was not committed.

## Live verification (INV-076746 / 1062)

`EInvoiceIrnRecoveryService::recover()` using config IP only.

| Item | Result |
|------|--------|
| Auth | 1; `ip_address` matches config; not localhost / `192.168.0.1` |
| Get-IRN | 1; `ip_address` matches config; password header empty |
| GENERATE | 0 |
| Cancel | 0 |
| Retries | 0 |
| IRN | `974b217a7c86b097e083d625fa28691e11879539376c1a2bf5d670c309438119` |
| AckNo | `172621144003124` |
| AckDt | `2026-09-10 14:57:00` |
| Invoice / e-invoice | unchanged (`issued` / `submitted`) |

## IRN OFF after

provider `none`; `NullEInvoiceGateway`; worker false; auto-issue false.

## Not performed

`deskd`, migrate, bind WhiteBooks as `EInvoiceGateway`, enable worker/auto-issue, GENERATE, Cancel, commit of `.env`, rollback.
