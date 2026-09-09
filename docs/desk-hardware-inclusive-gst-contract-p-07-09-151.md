# Permanent Hardware inclusive-GST invoice contract — RadiumDesk-P-07-09-151

**Date:** 2026-09-09  
**Prompt ID:** `RadiumDesk-P-07-09-151`  
**Mode:** Code fix + commit/push + named-file overlay. Do **not** issue RDE318435 / RDE318401 / RIN3512344 / RIN3512331.

## Repository / production (before)

| Item | Value |
| --- | --- |
| Repository | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` |
| Branch | `main` = `origin/main` |
| Before SHA | `35dd75a69f9a2b59d8e7a3eff95cf0190d1354c0` |
| Worktree | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` `35dd75a6` `[main]` |
| Remote | `origin` `git@github.com:radium-foundation/radium-desk.git` |
| Production | KVM `srv1910783.hstgr.cloud` / `187.127.129.16` `/var/www/radium-desk` overlay, no git |
| P-126 files | Production SHA-256 **MATCH** local HEAD (`HardwareInclusiveGstReconciler` `482605b3…`) |

## Diagnosis (read-only)

All four blocked orders: paid, `serials_allocated`, 1 serial, no invoice, no shipment. Exception: `GST amount does not match taxable value × rate.`

| Order | HF | Commerce | Gross | Qty | Stored taxable | Stored GST | gst% | Inclusive Δ | Exclusive Δ | P-126 `fromInclusiveGross` |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| RDE318435 | 11 / CO-000737 | ₹3849 | 1 | 3261.87 | 587.13 | null | 0 | +1 | taxable 3261.86 + tax 587.13 = **3848.99** FAIL |
| RDE318401 | 13 / CO-000739 | ₹2999 | 1 | 2541.52 | 457.48 | null | 0 | −1 | 2541.53 + 457.48 = **2999.01** FAIL |
| RIN3512344 | 17 / CO-001006 | ₹2649 | 1 | 2244.92 | 404.08 | 18.00 | 0 | +1 | 2244.92 + 404.09 = **2649.01** FAIL |
| RIN3512331 | 18 / CO-001012 | ₹2649 | 1 | 2244.92 | 404.08 | 18.00 | 0 | +1 | same as 2344 |

Successful:

- RDE318434: exclusive 0 + inclusive 0 → keep 2117.80 / 381.20
- RDE318516: exclusive −1, inclusive 0 → project 21177.97 + 3812.03 = 24990 (P-126 already works)
- RDE318517: exclusive 0 + inclusive 0 → keep 10588.98 / 1906.02

**Root cause: A** (inclusive rounding), contributing **D** (stored pair is inclusive-exact, exclusive-off by 1 paisa). Not qty multiplication (qty 1). Not jurisdiction (split never reached).

P-126 projected `tax = round(taxable × rate)` and required `taxable + tax = gross`. For 3849 / 2999 / 2649 that pair is impossible at 2 decimals. Contract `GST = gross − taxable` always matches gross; exclusive identity is then off by 1 paisa, so `GstSplitService` (default tolerance 0) would still fail unless Hardware mint opts in.

## Permanent contract

Existing reconciler reused: **YES**

1. Exact stored exclusive **and** inclusive identity → keep stored.
2. Both deltas ≤ 1 paisa → `taxable = round(gross / (1 + rate/100), 2)`, `GST = gross − taxable` (paise). `taxable + GST = gross`.
3. Either delta > 1 paisa → fail closed. Commerce not rewritten.
4. Rate: explicit legal slab `{0,5,12,18,28}`, else derived `round(tax/taxable×100, 2)` if that is a legal slab. Conflicting legal rates fail closed. Non-slab derived rates fail closed.
5. `GstSplitService` default unchanged. Hardware mint sets `inclusiveHardwareGst` so split allows **1 paisa** exclusive mismatch and still splits the **authoritative GST lump** (IGST vs CGST/SGST unchanged).

Projected invoices (not issued this prompt):

- RDE318435: taxable **3261.86**, GST **587.14**, total **3849.00**, TN B2C → IGST
- RDE318401: taxable **2541.53**, GST **457.47**, total **2999.00**, Assam B2C → IGST
- RIN 2649: taxable **2244.92**, GST **404.08**, total **2649.00**

## Not performed

Issue Invoice for the four blocked orders. Commerce tax edits. Existing invoice rewrite. Parcel/shipment/AWB/label/pickup/manifest. Operator outbox process. Migrations. `.env`.
