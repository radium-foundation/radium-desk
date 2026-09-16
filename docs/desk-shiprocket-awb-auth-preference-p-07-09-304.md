# Shiprocket AWB / auth remaining blockers (P-07-09-304)

**Date:** 2026-09-16  
**Prompt ID:** `RadiumDesk-P-07-09-304`  
**Type:** Smallest safe code correction. Named-file production overlay authorized after tests.

Ledger: `docs/cursor-prompt-ledger.md`. Next unused after `P-07-09-303`. `P-16-09-01` / `P-16-09-02` live on other worktrees and were not reused.

---

## Production inspect (read-only)

| Order | HF | Desk shipment | SR order | SR shipment | Desk courier | AWB | Provider state |
|-------|----|---------------|----------|-------------|--------------|-----|----------------|
| RBP145 | 814 | 64 / HW-RBP145 | 1589054485 | 1585271779 | 15084 Delhivery_Surface | null | **KNOWN** — HTTP 400 not serviceable; recovery already ran; recommended still 15084; 9 eligible including 15106 Surface |
| RBP167 | 934 | 63 / HW-RBP167 | 1589048784 | 1585266078 | 48 Ekart Logistics Air | SRAP0487843094 | **KNOWN** — AWB exists; recovery used recommended Air |
| RBP170 | 935 | 66 / HW-RBP170 | 1590363927 | 1586581189 | 15084 Delhivery_Surface | null | **UNKNOWN** — auth login DNS timeout 5001ms; reconcile before AWB |

Verified Desk preference source: production AWB history (15084 × 37, 15106 × 8, Surface-dominant). No previous `SHIPROCKET_PREFERRED_COURIER_IDS` env. SOP P-07-09-66/75: recommendation does not auto-select.

Auth: `HttpShiprocketGateway` is `bind()` not singleton, so each AWB request can login twice. Token not cached. Connect timeout 5s unchanged. Live DNS currently healthy (~1.6ms namelookup via systemd-resolved stub `127.0.0.53`). Timeout is intermittent resolver/network, not a wrong URL.

## Code

1. After definitive AWB “not serviceable”, choose one alternate from the fresh eligible list. Never reuse the rejected id.
2. Order: env preferred IDs (if currently eligible) → same provider `mode` as rejected/stored → recommended if eligible → one unranked eligible only on recovery.
3. Cache Shiprocket login tokens in Redis (`CACHE_STORE=redis`) with TTL − 120s. One extra login attempt on DNS/connect timeout only. AWB is not retried after timeout.

Do not change DNS. Do not hardcode 15084→15106. Do not create a second shipment.
