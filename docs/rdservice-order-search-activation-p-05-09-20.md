# Desk → rdservice.net order-search activation — RadiumDesk-P-05-09-20

**Date:** 2026-09-05 21:28 IST  
**Primary ID:** `RadiumDesk-P-05-09-20`  
**Companion:** `RadiumServiceNet-P-05-09-02`

Activate authenticated replacement lookup. Old Admin fallback retained. No invoice mint/remint. No AWS / DNS / vhost / Admin SSL work. DLQ not flushed.

---

## Production boundary

| App | Path | Action this ticket |
|---|---|---|
| Radium Desk | `/var/www/radium-desk` on KVM8 `srv1910783` / `187.127.129.16` | Shared token + RDService enablement; surgical overlay of `RdServiceClient.php` + `config/rdservice.php`; `config:cache`; worker restart |
| rdservice.net | `/var/www/rdservice.net.prod` | Shared token in `.env` only. No code overlay. Allow-list unchanged (`rdservice.net` default) |
| radiumbox.com | `/var/www/radiumbox.com` | NO — Not performed |
| Old Admin | AWS `admin.radiumbox.com` | NO — Not performed |

Local Desk worktree: `/Users/ravi/RadiumWebsites/radium-desk-pos-release` `main` `b282be3944a4e67908b68dd1f18265dc0e2e2f0c` (ahead of `origin/main` by 1).  
Local net worktree: `/Users/ravi/RadiumWebsites/rdservice.net` `wip/p04-08-03-einvoice-payment-export-ui` `fb3f361257f40f05064c82687714f5f65414185e` (unrelated payment WIP left in place except one added API test).  
Live Desk `deployed-commit.txt`: `800ed734` (pre-existing release marker; this ticket did not run `deskd` / full rsync).

---

## Token

Generated on KVM8 with `random_bytes(32)` / hex (64 chars). Written only to:

- `/var/www/radium-desk/.env` (`DESK_ORDER_API_TOKEN`)
- `/var/www/rdservice.net.prod/.env` (`DESK_ORDER_API_TOKEN`)

Both files `600`. Desk `bootstrap/cache/config.php` rebuilt and `600`. Tokens match. Value never printed, committed, or logged.

Desk also set:

- `RDSERVICE_ENABLED=true`
- `RDSERVICE_HOST=rdservice.net`
- `RDSERVICE_TIMEOUT_SECONDS=20`
- `RDSERVICE_BASE_URL=http://127.0.0.1` (after hairpin failure; see below)

Unchanged:

- `RADIUMBOX_ENABLED=true`
- `RADIUMBOX_BASE_URL=https://admin.radiumbox.com`
- net `INTEGRATION_ALLOWED_WEBSITES` absent (default `rdservice.net` only)

---

## Hairpin / origin path

Desk on the same KVM8 calling public `https://rdservice.net` intermittently timed out with 0 bytes (Cloudflare hairpin). Loopback HTTP to OLS with `Host: rdservice.net` is reliable:

| Desk fetch of `RD3449705` via `http://127.0.0.1` | Result |
|---|---|
| Run 1 | 200 / 11456 ms / `INV6745886` |
| Run 2 | 200 / 1319 ms / `INV6745886` |
| Run 3 | 200 / 1146 ms / `INV6745886` |

Public HTTP remains rejected except loopback. Public `http://rdservice.net` is still refused by the client. Token still never leaves the box.

---

## RD3449705 (read-only)

| Field | Replacement API / Desk consume | Stored Desk order 11050 |
|---|---|---|
| rdorderid | `RD3449705` | `RD3449705` |
| Box/Admin `orders.id` | `268507` | unchanged |
| invoice | `INV6745886` | still `NULL` (not persisted this ticket) |
| payment / status | Paid / Completed | `active` |
| serial | `7710951` | `7710951` |
| product | `MFS110` | `MFS 110` |
| customer | `Nareshkumar` | unchanged |
| website | `rdservice.net` | n/a |

Invoice `INV6745886` was not reminted. Desk row 11050 was not updated.

---

## Mapping (Old Admin → rdservice.net)

Desk `RdServiceOrderMapper` still runs the Admin-shaped payload through `RadiumBoxOrderSearchResponseMapper`, then overlays `data.snapshot`.

| Desk 360 / enrichment field | Old Admin | rdservice.net | Notes |
|---|---|---|---|
| invoice number | `data.order.invoicecode` | `data.order.invoicecode` + `snapshot.invoice_number` | Present on API; stored 11050 left NULL |
| serial | `rd_order.serial_no` | same + `snapshot.serial_number` | Match |
| product / model | `rd_order.product_name` | same + `snapshot.model` | Match |
| customer / phone / email | `rd_order.userdetails` | same + snapshot | Match |
| GST | `gst_no` | same | NULL on this order |
| payment / order status | `payment_status` / `status` | same | Paid / Completed |
| history / lines | Admin history | `data.history` (3) / `data.lines` (3) | Returned; not persisted this ticket |

No required Desk 360 field was invented.

---

## Eligibility / security probes

| Case | HTTP | Message |
|---|---|---|
| Missing token | 401 | Unauthorized |
| Wrong token | 401 | Unauthorized |
| `RD3449705` + token | 200 | OK |
| `RDE318360` | 400 | rdorderid is invalid |
| `INQ-1` | 400 | rdorderid is invalid |
| `RD` (malformed) | 400 | rdorderid is invalid |
| `RD999999999` | 404 | RD Order not found |
| `RD3511916` / `RD3511920` | 404 | Not in `rdservice_net_prod` (other-website / absent). Allow-list not broadened |

Responses did not leak the token.

---

## Tests

- Desk: Pint on touched client/config/test files. PHPUnit `RdServiceClientTest` + mapper + lookup: **29 tests / 131 assertions** passed after loopback Host support.
- Earlier this ticket: Desk focused suites **77 / 354**; net `RdOrderDeskApiTest` + lookup **22 / 144**; added `test_existing_historical_invoicecode_is_returned_unchanged` (**1 / 6**).
- Net Pint `--dirty` briefly reformatted unrelated `OrderController.php`; that file was restored to HEAD. **Local uncommitted OrderController WIP on the net payment branch was lost in that restore.** Other net WIP (`RdPaymentFulfillment.php`, `routes/web.php`, Cashfree tests) remains.

---

## Backup / rollback

Backups on KVM8:

- `/home/ravi/desk-prod-backups/p-05-09-20-20260905T155122Z` — original Desk + net `.env` and Desk `config.php`
- `/home/ravi/desk-prod-backups/p-05-09-20-loopback-20260905T155836Z` — pre-loopback Desk client, `rdservice.php`, `.env`, `config.php`

Rollback: restore those files, `php artisan config:cache` in `/var/www/radium-desk`, `chmod 600` env/config, `sudo supervisorctl restart radium-desk-queue-worker`. Do not flush DLQ.

---

## Soak notes

- Desk `/up` 200. Worker `RUNNING`. Queue `jobs=0`.
- Statutory invoices **0**. Sequences **0**. No mint.
- `failed_jobs` **44** (was 42 at P-05-09-19). New IDs 49/50 at 21:21:11–12 are `RadiumBoxOrderEnrichmentJob` for Desk orders 50968 `RD3511916` and 50970 `RD3511920` — **HTTP 526 via Admin** before token/config were live, and those IDs are **not** in `rdservice_net_prod`. Not retried. Not flushed.
- Eligible net orders now take RDService first. Other-website `RD*` still 404 → Admin fallback (still 526). That is the retained fallback, not a new dependency.

---

## Gates

| Gate | Verdict |
|---|---|
| REPLACEMENT API | PASS |
| TOKEN AUTHENTICATION | PASS |
| DESK → RDService LOOKUP | PASS |
| RD3449705 | PASS |
| 360 DATA MAPPING | PASS (consume OK; stored invoice still NULL by design) |
| OLD ADMIN FALLBACK | PRESENT |
| SECURITY | PASS |
| PRODUCTION HEALTH | PASS |
| SOAK | READY |
| ADMIN RETIREMENT | NOT READY |
| AWS SHUTDOWN | NOT READY |
