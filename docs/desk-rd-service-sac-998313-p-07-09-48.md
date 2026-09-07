# RD Service SAC mapping — RadiumDesk-P-07-09-48

**Date:** 2026-09-07  
**Prompt ID:** `RadiumDesk-P-07-09-48`  
**Mode:** Establish Owner rule **RD Service = SAC 998313**. Do not rewrite issued invoices. Do not deploy.

Ledger file is `docs/cursor-prompt-ledger.md`. Last committed row is **P-07-09-47**. This ticket uses the next unused ID **P-07-09-48**.

---

## Identity

| Item | Value | Class |
|---|---|---|
| Repository | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` `git@github.com:radium-foundation/radium-desk.git` | VERIFIED |
| Branch | `main` (tracks `origin/main`) | VERIFIED |
| Before SHA | `e4c195cc814bec07114bc7d42668a18b6ccd429f` | VERIFIED |
| Worktree | This path. Sibling worktrees were not used. | VERIFIED |

---

## Trace

```
Spoke (rdservice.in / .net)
  → HMAC POST /api/v1/channel-orders  lines[].hsn_sac
  → ChannelIngestService persists commerce_order_items.hsn_sac
  → StatutoryInvoiceService::issueFromCommerceOrder copies line HSN
  → statutory_invoice_items.hsn_sac
  → SimplePdfRenderer prints stored HSN/SAC
```

Desk application code had **no** `998314` default and **no** product/service master SAC for RD Service. **VERIFIED.**

`998314` entered as the spoke line field. The description template already said `(SAC - 998313)`. Desk copied both fields. **VERIFIED (code + prior production SELECT on INV-276710 / INV-27671).**

PDF renderer was not the source and was not special-cased.

---

## Mapping

Authoritative config: `statutory_invoices.service_sac.rd_service.sac = 998313`.

Match only when:

- channel is `rdservice_in` or `rdservice_net`, **and**
- SKU is `RD-SVC` **or** the description matches an explicit RD Service needle.

Unmatched lines keep the incoming HSN/SAC. There is no generic default to `998313` or `998314`. A future service must add its own config entry with its own verified SAC.

Applied at:

1. **New ingest persist** — so new commerce lines store 998313.
2. **New invoice mint** — so already-ingested unissued RD Service rows still mint 998313 without updating the commerce row.

Issued invoices are returned as-is. **VERIFIED (existing `findBySource` path).**

---

## Data safety

| Record | Action |
|---|---|
| Issued statutory invoices | **NO modification.** |
| `INV-276710` / historical PDFs | **NO regenerate.** |
| Already-ingested unissued commerce lines | Left as stored. Mint applies 998313 to the **new** invoice only. |
| Production DB | **Not queried** this ticket. Unissued production count **UNKNOWN**. |

No migration. No bulk UPDATE.

---

## Not performed

No production write. No remint. No IRN. No deploy. Unrelated dirty docs were not staged.
