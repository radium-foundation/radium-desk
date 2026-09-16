# Shiprocket auth token cache — RadiumDesk-P-16-09-02

**Date:** 2026-09-16  
**Prompt ID:** `RadiumDesk-P-16-09-02`  
**Type:** Code only. No production overlay. No AWB. No courier change. No shipment mutation.

Ledger: `docs/cursor-prompt-ledger.md`. Next unused after investigation `P-16-09-01` / main `P-07-09-289` (do not reuse `P-07-09-290`–`292`). This ticket: **P-16-09-02**.

---

## Verdict

`HttpShiprocketGateway` now reuses a successful Shiprocket login token across requests via Laravel `Cache`, with a 120s expiry margin and an auth lock. The 5s connect timeout is **unchanged**. Token caching reduces unnecessary `/auth/login` calls; it does **not** fix DNS instability.

Did **not** deploy. Did **not** call production Shiprocket. Did **not** retry RBP145 / RBP167 / RBP170.

---

## Timeout

Connect timeout remains **5 seconds** (`SHIPROCKET_CONNECT_TIMEOUT_SECONDS` default). Production DNS failures already exhaust this budget (`cURL error 28` after 5000ms). Healthy `apiv2` namelookup is ~8ms. Raising the default would only delay the same outage.

---

## Cache contract

| Item | Behavior |
|------|----------|
| Store | Default Laravel cache (`CACHE_STORE`) |
| Key | `shipping:shiprocket:token:` + SHA-256(email + NUL + password) |
| Value | `{token, ttl_seconds, cached_at}` — never logged |
| TTL | Shiprocket `expires_in` / `ttl` when present, else 86400; cache duration = TTL − 120s |
| Miss / cache down | Normal login; in-process token still used for the rest of the request |
| Auth failure / empty token | Nothing cached |
| Downstream HTTP 401 | Cache forgotten; retryable “token was rejected” (no automatic AWB retry) |
| HTTP 400 AWB | Non-retryable; cache kept |
| Lock | `Cache::lock` 20s, wait 5s; timeout or unsupported lock falls back to login |

---

## Tests

Focused `HttpShiprocketGatewayTest`: first cache, reuse, near-expiry refresh, auth failure, empty token, 401 invalidation, shared-cache lock/dedup, null cache fallback, null gateway, AWB 400 not retried. Courier/create payloads unchanged.

---

## Production

Not touched. Overlay/deploy is a later gate.
