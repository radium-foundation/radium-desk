# Targeted hardware fulfilment unblock — RDE318467 / RDE318437 / RDE318438 — RadiumDesk-P-07-09-223

**Date:** 2026-09-11  
**Prompt ID:** `RadiumDesk-P-07-09-223`  
**Mode:** Production recovery via existing Box handoff + Desk fulfilment workflow. Surgical eligibility overlay only.

---

## Pre-change verification

| Item | Value |
|---|---|
| Repository | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` |
| Branch | `feat/hardware-fulfilment-ui-ux` |
| Before SHA | `aeaf1824b54cb72da2cab4da1ee2c16484c2f1d3` |
| Worktree | Clean except this ticket |
| Remote | `origin` → `git@github.com:radium-foundation/radium-desk.git` |
| Production | KVM `deskvps` / `/var/www/radium-desk` DB `radium_desk` |
| Box | `/var/www/radiumbox.com` DB `radiumbox_prod` |
| Ledger next ID | **P-07-09-223** (after P-07-09-222) |

---

## Root causes (summary)

| Order | Blocker class | Cause |
|---|---|---|
| RDE318437 | Missing Commerce + split-tender | Box handoff 46 pending without `tenders`; Desk had Cashfree ₹2000 only vs Box ₹2499 (wallet ₹499). |
| RDE318467 | Missing Commerce + split-tender | Box handoff 60 pending without `tenders`; Desk Cashfree ₹1900 vs Box ₹2499 (wallet ₹599). |
| RDE318438 | Owner HOLD + pre-P7A payload | Hardcoded Desk HOLD; Box handoff 48 lacked `model_id` though order line has model 1006. |

**Awaiting Handoff** was correct: these orders had **no Commerce/HF yet**, not post-serial handoff.

---

## Production backup

| Item | Value |
|---|---|
| Path | `/var/www/radium-desk/app/Services/HardwareFulfilment/HardwareFulfilmentEligibility.php.pre-P-223-20260911T030946Z` |
| Rollback | Restore backup file; do **not** revert commerce/HF/serial/invoice/shipment rows without owner finance review |

---

## Final production state (2026-09-11 IST)

| Order | HF | State | Commerce | Wallet | Serial | Invoice | AWB |
|---|---|---|---|---|---|---|---|
| RDE318437 | 56 | `shipped` | CO-001585 ₹2499 | ₹499 | 10564333 | INV-671124 | 77178172794 |
| RDE318467 | 57 | `shipped` | CO-001586 ₹2499 | ₹599 | 10564239 | INV-671125 | SF3415118984KAK |
| RDE318438 | 58 | `shipped` | CO-001587 ₹3799 | — | 10983282 | INV-671126 | 14112363526697 |

Hardware Workspace UI: `completed / Ready / Completed` for all three.

---

## Actions performed

1. Box `desk:rebuild-handoff` 46 / 60 (split tenders) and 48 (P7A model 1006).
2. Box `desk:deliver-handoff` 46 (already delivered) / 60; Desk isolated ingest `--payload` for 438 after HOLD release.
3. Desk serial allocation + auto invoice (P-213 trigger).
4. Catalog parcel snapshot attach; Shiprocket courier select/create/AWB; package photo; `markShipped`.
5. Surgical production overlay: `HardwareFulfilmentEligibility.php` with `AUTHORIZED_HOLD_RELEASE_SOURCE_IDS` for RDE318438.

---

## Tests

- `HardwareFulfilmentIsolatedOneOrderTest` — 32 passed  
- `HardwareRecoveredFulfilmentAuthorizationTest` — included in run  
- Pint on eligibility file — passed

---

## Remaining risks

- RDE318438 Box `desk:deliver-handoff` remains blocked by `PRE_P7A_HOLD` on Box; Desk ingest used verified rebuilt payload instead.
- Split-tender orders depend on Box rebuild emitting matching `tenders`; do not ingest Cashfree-only totals.
- Owner HOLD list still contains RDE255714 / RDE313554.
