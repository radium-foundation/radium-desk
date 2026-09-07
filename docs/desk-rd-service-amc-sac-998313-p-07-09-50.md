# RD Service + AMC SAC mapping — RadiumDesk-P-07-09-50

**Date:** 2026-09-07  
**Prompt ID:** `RadiumDesk-P-07-09-50`  
**Mode:** Correct statutory SAC mapping so **RD Service and AMC both resolve to 998313**. Hardware stays **84716050**. Other services keep their own explicit SAC. Do not rewrite issued invoices. Do not deploy.

Ledger file is `docs/cursor-prompt-ledger.md` (no `cursor-prompt-log.md`). Last committed row is **P-07-09-48**. **P-07-09-49** was a chat-only production SAC audit (not a code change) and is recorded in the ledger so the ID is not reused. This ticket uses the next unused ID **P-07-09-50**.

---

## Identity

| Item | Value | Class |
|---|---|---|
| Repository | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` `git@github.com:radium-foundation/radium-desk.git` | VERIFIED |
| Branch | `main` (tracks `origin/main`) | VERIFIED |
| Before SHA | `853066067f4b330a05d95143f25d3722698b4068` | VERIFIED |
| Worktree | This path. Sibling worktrees were not used. | VERIFIED |

---

## AMC identification

Priced AMC on `rdservice_in` / `rdservice_net` is **not** identified by `amcid`.

Production read-only SELECT from P-07-09-49 (chat audit, not re-run this ticket):

| Evidence | Result | Class |
|---|---|---|
| Priced AMC commerce descriptions | `AMC : 1 Year Standard` / `Unlimited` / `Comprehensive` | VERIFIED (prior SELECT) |
| `commerce_order_items.amcid` on those priced AMC lines | **NULL on all of them** | VERIFIED (prior SELECT) |
| `rdserviceid` / SKU / `product_id` on those lines | NULL | VERIFIED (prior SELECT) |
| `statutory_invoice_items.amcid` | column does not exist | VERIFIED (prior schema inspect) |
| `amcid` on ingest | bundled FK on **hardware** (`radiumbox_com`) lines | VERIFIED (code + prior fulfilment docs) |

Authoritative identifier used by this mapping:

1. Channel is `rdservice_in` or `rdservice_net`, **and**
2. Description contains `AMC :` or `amc:` (tight needles; bare `amc` is rejected).

Optional future identifier: `match_amcid => true` so a **service-channel** line with `amcid > 0` also maps. Hardware channels are excluded, so a Box line with bundled `amcid` keeps HSN `84716050`.

Do **not** use `amcid IS NOT NULL` alone. That would mis-classify bundled hardware.

---

## Mapping

| Service | SAC | Match |
|---|---:|---|
| RD Service | **998313** | `rdservice_in` / `rdservice_net` + SKU `RD-SVC` or RD description needles |
| AMC | **998313** | same channels + `AMC :` / `amc:` (or future service-channel `amcid`) |
| Hardware | **84716050** | unchanged; Box / fulfilment mint does not use this resolver |
| Other / unmatched service | incoming or its own config entry | no generic 998313 |

There is still **no** generic default SAC. Overlapping mappings fail closed.

Applied at:

1. **New ingest persist** — new commerce lines store the resolved SAC.
2. **New invoice mint** — already-ingested unissued rows mint the resolved SAC without updating the commerce row.

Issued invoices are returned as-is (`findBySource`). **VERIFIED.**

PDF renderer prints stored `statutory_invoice_items.hsn_sac` only. **VERIFIED.**

---

## Data safety

| Record | Action |
|---|---|
| Issued statutory invoices | **NO modification.** |
| Historical PDFs | **NO regenerate.** |
| Already-ingested unissued commerce lines | Left as stored. Mint applies 998313 to the **new** invoice only. |
| Production DB | **Not queried or written** this ticket. |
| Migrations / bulk UPDATE | **Not performed.** |

---

## Not performed

No production write. No remint. No IRN. No deploy. Unrelated dirty docs were not staged.
