# Owner approval — POS retail composite-supply shipping (e-Invoice)

**Prompt ID:** RadiumDesk-P-19-52 (ChatGPT **P-19-52**)  
**Date:** 2026-11-19  
**Production baseline at approval:** v4.1.100 / `13c13928f`  
**PR:** [#362](https://github.com/radium-foundation/radium-desk/pull/362) — `feature/p19-47-pos-shipping-gst`  
**Cursor ledger chat:** `bd0ac5bd-88f0-48e0-aacd-66eca9be9aef`

---

## Explicit Owner decisions (P-19-52)

| # | Decision | Status | Notes |
|---|----------|--------|-------|
| 1 | POS retail delivery treated as **composite supply** (goods = principal) for the supported Radium POS retail arrangement | **APPROVED** | Recorded 2026-11-19 via Owner instruction to P-19-52. **Does not** authorize production deploy or env change. |
| 2 | Principal selection on supported baskets: **highest taxable-value** product line; **mixed GST-rate** baskets blocked | **APPROVED** | Implemented in `PosRetailShippingCompositePrincipal`; equal taxable + different HSN fails closed. |
| 3 | **`STATUTORY_POS_RETAIL_SHIPPING_COMPOSITE_SUPPLY`** may be enabled only via **separate controlled production rollout** (initially OFF) | **APPROVED** | Default `false` in `config/statutory_invoices.php`. |

---

## Out of scope of this approval

- Production deployment, migrations, or `.env` mutation  
- Automatic IRN generation, invoice 23163 retry, mint, or WhiteBooks calls  
- Channel freight-auction SAC classification  
- Independent freight / pure-agent transport not covered by the supported POS composite path  

---

## Operational rollout gate (post-merge, pre-IRN)

1. Deploy merged release (separate Owner deploy authorization).  
2. Keep `STATUTORY_POS_RETAIL_SHIPPING_COMPOSITE_SUPPLY=false` until rollout checklist signed.  
3. Enable flag in controlled window; monitor statutory skip reasons and IRP gaps.  
4. Invoice **23163** (INV-0767941): candidate for **separate** post-enablement e-Invoice re-evaluation only — no automatic requeue.

**Rollback:** Disable composite flag; optional revert to prior Desk build; no statutory row rewrite required (mapper-only).