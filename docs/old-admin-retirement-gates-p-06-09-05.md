# Old Admin remaining retirement gates — RadiumDesk-P-06-09-05

**Date:** 2026-09-06  
**HEAD at inspect:** `db370dffc8c7411a8927915827913f3bf5bb723a`  
**Branch:** `main` = `origin/main` (clean)

Read-only audit. No application, `.env`, DNS, Cloudflare, AWS, Cashfree, queue, invoice, or Old Admin change.

**Old Admin retirement:** **NOT READY.** Do not retire. Do not delete or disable the hostname, origin, or residual Desk client.

Cashfree account-webhook gate is already **PASS** (`RadiumDesk-P-06-09-04`). This ticket did not reopen Cashfree.

Companion historical reports (do not treat as current SoT): `docs/desk-old-admin-retirement-gate.md` (P-05-09-18), `docs/old-admin-retirement-gates-p-05-09-23.md`.

---

## Gate matrix

| Gate | Status | Evidence | Risk/Blocker |
|---|---|---|---|
| Cashfree account webhook | **PASS** | Authenticated Merchant Dashboard (P-06-09-04): active success-payment webhook is `https://desk.radiumbox.com/api/webhooks/cashfree`, version `2025-01-01`, DEFAULT, HTTP 200, Domain Health Good 100%. Not re-inspected this ticket. | — |
| DNS / hostname | **UNKNOWN** | Public `admin.radiumbox.com` still resolves to Cloudflare anycast `104.21.42.236` / `172.67.212.65` plus CF AAAA. Zone NS `angelina.ns.cloudflare.com` / `tom.ns.cloudflare.com`. Cloudflare dashboard **not opened**. Proxied origin record **UNKNOWN**. Public HTTPS timed out (0 bytes) IPv4 and IPv6; not the 526 seen on 2026-09-05. | Cannot authorize DNS deletion. Hostname is still live in the public zone. |
| Origin / infrastructure | **UNKNOWN** | Former documented origin `13.234.230.151` TCP 22/80/443 timed out from the auditor laptop **and** from KVM8 `187.127.129.16`. AWS console **not opened**. No production `admin.radiumbox.com` vhost on KVM8 (`/var/www` has `beta-admin` only). | Unreachable ≠ stopped ≠ terminated. Do not terminate. |
| Old Admin leftovers | **PASS** | Live `app/` trees on Desk, Box, in, net, sign, online: **0** `admin.radiumbox.com` / `13.234.230.151`. Same strings **absent** from those production `.env` files. Desk flags: `RADIUMBOX_ENABLED=false`, `RADIUMBOX_BASE_URL` empty, `RADIUMBOX_ADMIN_FALLBACK_ENABLED=false`. Desk `app/` has no Admin hostname literal. | Residual `RadiumBoxClient` still calls `{RADIUMBOX_BASE_URL}/api/search/order` **if** flags are re-enabled. Tests still mock the hostname. |
| Historical invoice / reprint | **PASS** | Lookup is Box `GET /api/integrations/v1/historical-invoices/{INV*}`. Print is Desk `finance.invoices.historical-print`. C360 uses the same read-only path. Live P-06-09-02: `INV6745886` / `RD268507` → incident 11178, print `read_only=true`, `source=radiumbox_com`, no `admin.radiumbox.com` in HTML. Statutory count still **0** (no remint). | Opening a mapped case from the queue without `?historical_invoice=` still hides the card (`orders.invoice_number` NULL). That is a Desk UX limit, not an Admin dependency. |
| Non-Cashfree callers | **UNKNOWN** | **VERIFIED** no application caller in the live `app/` trees above; **0** Desk `laravel.log` lines containing `admin.radiumbox.com` on 2026-09-06; last Desk Admin HTTP `2026-09-05 23:36:59` (pre-cutover worker). `failed_jobs` still **57**, latest id 63 at that same timestamp. | Human bookmarks, wallet SOP, `ba.radiumbox.com` / `media.radiumbox.com`, other AWS OLS maps, and non-repo systems were **not** proven unused. |

---

## A. DNS / hostname

| Item | Finding | Class |
|---|---|---|
| `admin.radiumbox.com` A | `104.21.42.236`, `172.67.212.65` (TTL 300) | **VERIFIED** `dig` 2026-09-06 |
| AAAA | `2606:4700:3034::6815:2aec`, `2606:4700:3034::ac43:d441` | **VERIFIED** |
| CNAME at this name | none | **VERIFIED** |
| Zone NS | `angelina.ns.cloudflare.com`, `tom.ns.cloudflare.com` | **VERIFIED** |
| Cloudflare dashboard / orange-cloud origin | Not opened. Proxied origin IP/CNAME unknown this session | **UNKNOWN** |
| Public HTTPS `/`, `/login`, `/api/search/order`, `/admin/print/invoice/268507` | curl 28 timeout, 0 bytes, IPv4 to `104.21.42.236` and IPv6 to CF | **VERIFIED** this session |
| Contrast vs P-05-09-18 | That ticket recorded public **526**. This session did not reproduce 526 | **VERIFIED** difference; cause **UNKNOWN** |

DNS was not changed.

---

## B. Origin / infrastructure

| Item | Finding | Class |
|---|---|---|
| Documented former origin | AWS/EC2 `13.234.230.151`, tree `/var/www/admin.radiumbox.com` (P-05-09-18 / P-05-09-19) | prior **VERIFIED**; not re-SSHed |
| Origin 22 / 80 / 443 from laptop | `connect_ex` timeout (~4s) | **VERIFIED** unreachable from this network |
| Same ports from KVM8 | `connect_ex=11` timeout (~4s) | **VERIFIED** unreachable from Desk production host |
| Insecure origin HTTPS (P-05-09-18) | Login 200 + expired LE cert `notAfter=Jun 1 2025` | **historical VERIFIED**; **not reproduced** now |
| AWS instance state | Console not opened. SSH not attempted | **UNKNOWN** (stopped / terminated / SG / path) |
| KVM8 production Admin vhost | Absent. `/var/www/beta-admin` exists (`APP_URL=https://ba.radiumbox.com`, staging name “Admin Portal Beta”) | **VERIFIED** path/name only |
| `ba.radiumbox.com` | Resolves to the same CF anycast; public HTTPS **526** | **VERIFIED**; not the `admin` hostname |
| Load balancer / other AWS OLS maps | Not re-enumerated | **UNKNOWN** |

Infrastructure was not started, stopped, terminated, or modified.

---

## C. Old Admin application leftovers (Desk + live siblings)

| Finding | Kind | Class |
|---|---|---|
| `RadiumBoxClient` `GET {base}/api/search/order` | Residual helper. Gated by `RADIUMBOX_ENABLED` | **VERIFIED** source |
| `OrderEnrichmentLookupService` / `RadiumBoxService` Admin branch | Residual. Gated by `RADIUMBOX_ADMIN_FALLBACK_ENABLED` (default false) | **VERIFIED** source |
| `config/radiumbox.php` + `config/order_lookup.php` flags | Residual config. Comments say production must stay false | **VERIFIED** |
| `.env.example` | `RADIUMBOX_ENABLED=false`, `RADIUMBOX_BASE_URL=`, fallback false | **VERIFIED** |
| Desk `app/` hostname literal `admin.radiumbox.com` | **None** | **VERIFIED** |
| Live production `.env` on Desk/Box/in/net/sign/online | **None** contain `admin.radiumbox.com` or `13.234.230.151` | **VERIFIED** |
| Live `app/` on those six trees | **None** contain those strings | **VERIFIED** |
| Tests / docs mentioning the hostname | Historical / test doubles | **VERIFIED** not a production caller |
| `UNIVERSAL_ASSIGNMENT_REMOVE_SHIFT_ADMIN_FALLBACK` | Unrelated workforce flag | **VERIFIED** different meaning |

---

## D. Historical invoice / reprint

| Path | Requires Old Admin? | Class |
|---|---|---|
| Historical lookup | **No** — Box spoke `historical_invoice_path` | **VERIFIED** `config/order_lookup.php` + `HistoricalInvoiceLookupService` |
| Customer 360 card | **No** — resolver + query param / existing Desk invoice number | **VERIFIED** source + P-05-09-28 / P-06-09-02 live |
| Print | **No** — Desk Blade `historical-print`; read-only Box payload | **VERIFIED** P-06-09-02 |
| Old Admin `GET /admin/print/invoice/{orders.id}` | Residual Admin route in the Admin **source** repo. Not used by Desk print | **VERIFIED** prior Admin source; **not** a Desk caller |
| Fallback if Box lookup fails | `not_found` / `source_unavailable`. Does **not** call Admin when fallback is false | **VERIFIED** source |
| Numbering | Historical `INV*` unchanged. Desk statutory series separate (`statutory_invoices=0`) | **VERIFIED** |

---

## E. Non-Cashfree callers

| Candidate | Classification | Class |
|---|---|---|
| Desk enrichment / recovery / search | Not an active production caller (flags off; 0 log hits 2026-09-06) | **VERIFIED** |
| Box / in / net / sign / online app | No Admin hostname in live `app/` | **VERIFIED** |
| Cashfree account webhook | Desk URL (out of scope; already PASS) | **VERIFIED** P-06-09-04 |
| Admin `CaseFree` named notify routes | Residual Admin source; no matching Admin routes in prior tickets | historical; **not** re-opened |
| Human Admin UI / wallet credits | Still **UNKNOWN** (P-05-09-18 item preserved) | **UNKNOWN** |
| Bookmarks / SOP to `admin.radiumbox.com` | **UNKNOWN** | **UNKNOWN** |
| `media.radiumbox.com` | Public HTTPS **200** via Cloudflare. Relationship to Old Admin e-invoice **UNKNOWN** this ticket | **UNKNOWN** |
| `ba.radiumbox.com` / KVM8 `beta-admin` | Separate staging hostname; public **526** | **VERIFIED** exists; production use **UNKNOWN** |

---

## Production safety (re-read only)

| Check | Result |
|---|---|
| `RADIUMBOX_ENABLED` | `false` |
| `RADIUMBOX_BASE_URL` | empty |
| `RADIUMBOX_ADMIN_FALLBACK_ENABLED` | `false` |
| Spoke lookups | in + Box + net enabled (loopback Host routing) |
| Desk `/up` | 200 |
| `failed_jobs` | **57** — not flushed |
| `statutory_invoices` | **0** — not minted |
| Admin log 2026-09-06 | **0** |

---

## Retirement decision

**NOT READY.**

Application replacement for order lookup, historical reprint, and the Cashfree **account** webhook is in place. That is not the same as Old Admin being retired.

Exact remaining blockers before any retirement execution ticket:

1. **Cloudflare / DNS** — `admin.radiumbox.com` still exists. Dashboard records, page rules, and proxied origin were not inspected.
2. **AWS origin lifecycle** — `13.234.230.151` is unreachable from Desk and from this laptop. Instance state is unknown.
3. **Non-application use** — humans, wallet SOP, `media.radiumbox.com`, `ba.radiumbox.com`, and other possible AWS hostnames are unproven unused.
4. **Residual Desk client** — keep flags false. Removing the client is a later code ticket, not this audit.

Do **not** delete DNS. Do **not** terminate EC2. Do **not** enable Admin fallback.
