# Isolated READY — RDE318435 / HF11 and RDE318401 / HF13 — RadiumDesk-P-07-09-134

**Date:** 2026-09-09  
**Prompt ID:** `RadiumDesk-P-07-09-134`  
**Mode:** READY only. One order at a time. Stopped after `ready_for_fulfilment`. No allocate / invoice / ship.

Last used ledger ID: **P-07-09-133**. This ticket: **P-07-09-134**. The two orders were inspected independently; they are not equivalent.

Production: KVM `srv1910783` / `187.127.129.16` `/var/www/radium-desk` DB `radium_desk` `https://desk.radiumbox.com`. PHP `/usr/local/lsws/lsphp84/bin/php`.

Local repo: `/Users/ravi/RadiumWebsites/radium-desk-pos-release` `main` HEAD `bd966902` (unchanged). No application code change. Existing isolated `desk:fulfil-hardware --step=ready` used. `--force` not used.

---

## Eligibility (before, 12:58:36 IST)

HF count 16. RIN HF 0. Frozen HF 0. HOLD HF 0. Blocked RDE318400 / HF14 remained `ingested`. Each target had one fulfilment, paid Cashfree commerce, physical line, Owner SKU map ready, not frozen / HOLD / RIN / blocked-until-authorized. Serials 0. Invoice none. Shipment/AWB none. `assertIsolatedTarget=PASS`. Allocate correctly refused with `Serial allocation requires READY_FOR_FULFILMENT. Payment success is not enough.`

| Order | HF | Commerce | Support | Cashfree | Product | Qty | POS | Classify |
|---|---:|---|---|---|---|---:|---|---|
| RDE318435 | 11 | CO-000737 / 737 paid ₹3849 | 51536 | `6428915642` SUCCESS UPI; gateway `6850134529`; bank `625090105788`; webhook 64301 | Mantra MIS 100 V2 `1006` → **30** `RBMIS100IR` (rdserviceid **1130**) | 1 | Tamil Nadu | **ELIGIBLE** |
| RDE318401 | 13 | CO-000739 / 739 paid ₹2999 | 51369 | `6426687634` SUCCESS UPI; gateway `6847881997`; bank `625055196768`; webhook 63987 | Mantra MIS 100 V2 `1006` → **30** `RBMIS100IR` (rdserviceid **1128**) | 1 | Assam | **ELIGIBLE** |

Stock at inspect: `RBMIS100IR` available **650** (DELHI-RETAIL 649 / MUMBAI 1). Serials were **not** allocated.

---

## Mutations

One-at-a-time `desk:fulfil-hardware {id} --step=ready` after a passing dry-run. Events 97–98 `ingested → ready_for_fulfilment` reason `isolated_ready_for_fulfilment`. `hardware.box.callback` rows for HF 11 and 13 = **0**.

| Order | Dry-run | Live IST | After |
|---|---|---|---|
| RDE318435 | `ok=true` identifier RDE318435 planned=ready commerce 737 `No writes were performed.` | 12:59:34 | HF 11 `ready_for_fulfilment`; `canAllocate=true`; qty 1 model 1006; serials 0 |
| RDE318401 | `ok=true` identifier RDE318401 planned=ready commerce 739 `No writes were performed.` | 12:59:57 | HF 13 `ready_for_fulfilment`; `canAllocate=true`; qty 1 model 1006; serials 0 |

Commerce `updated_at` unchanged (737: 2026-09-08 23:59:42; 739: 2026-09-09 00:01:15). Payment, product, SKU, quantity unchanged. Invoice 0. Shipment 0. AWB none. Fulfilment count still 1 per source. Commerce count still 1 per source. HF total still 16.

Unrelated HF 1–10, 12, 14–16 `state` + `updated_at` + `ready_at` unchanged vs the 12:58:36 snapshot, including RDE318516/517/434/469/503/500/490/489/487/486/482, blocked RDE318400, and no RIN/frozen/HOLD fulfilment rows.

Operator may now select the **actual physical serial** in the existing Hardware Fulfilment UI. Allocate / invoice / parcel / Shiprocket / AWB / label / pickup / manifest / global outbox / `--force` / raw SQL / migrate / `.env`: **NO — Not performed**.
