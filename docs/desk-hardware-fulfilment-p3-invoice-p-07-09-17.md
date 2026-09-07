# P3 hardware statutory invoice writer and serial-aware PDF

**Project:** Radium Desk  
**Prompt:** **RadiumDesk-P-07-09-17**  
**Date:** 2026-09-07  
**Type:** Implementation. Hardware issuer + `mint()` orchestration + Annexure A PDF.  
**Prior:** P-07-09-12 … P-07-09-16.

Classification: **VERIFIED** / **OWNER-LOCKED** / **INFERRED** / **UNKNOWN**.

---

## 0. Repository verification (before modify)

| Item | Value | Class |
|------|-------|-------|
| Workspace | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` | VERIFIED |
| Branch | `main` (P1+P2 ahead of origin) | VERIFIED |
| Before SHA | `bcdffe25695458b2918077686ddc36e3acf45d9a` | VERIFIED |
| P1/P2 | persist, hash, payment evidence, serial-first guards | VERIFIED |
| Architecture vs P0–P2 | Matches serial-first. P0’s “call `issueFromCommerceOrder`” is **not** used: that wrapper still runs the HSN→service/product matrix and would give Delhi B2C `INV-07671`. Sole allocator remains `mint()`. | VERIFIED |

---

## 1. Hardware invoice path

`HardwareFulfilmentInvoiceService::issueInvoice()`:

1. Refuse frozen `RDE*` ids.
2. Lock fulfilment. Return existing invoice if already linked (idempotent).
3. `assertCanIssueInvoice` — state must be `SERIALS_ALLOCATED`.
4. Validate allocated serials: non-empty, unique, count = physical qty, linked to this fulfilment.
5. `HardwareIssuer::require(branch_code, buyer_gstin)`.
6. `StatutoryInvoiceService::mint()` with `numberingLocation` from the hardware issuer.
7. Link commerce + fulfilment. Lock serial snapshot in fulfilment metadata.
8. Generate PDF **after** the link so Annexure A can resolve serials. Do not swallow PDF failure.
9. `queueEinvoiceIfEligible` (existing Null gateway; `worker_may_mint=false`).
10. Transition to `INVOICE_ISSUED`.

Does **not** call `issueFromPosSale`, `issueFromSupportOrder`, `issueFromCommerceOrder`, or Admin `GenrateInvoice`.

Identity remains `statutory:radiumbox_com:commerce_order:RDE*`.

---

## 2. Hardware issuer / series

| Stock branch | Buyer GSTIN | Location | FY 2026-27 |
|--------------|-------------|----------|------------|
| `DELHI-RETAIL` | empty | `delhi_b2c` | `INV-671…` |
| `DELHI-RETAIL` | valid | `delhi` | `INV-07671…` |
| `MUMBAI` | empty or valid | `mumbai` | `INV-27671…` |
| other | — | fail-closed | — |

Invalid non-empty GSTIN fail-closed. Customer state and place of supply are **not** issuer inputs.

POS `requireForProductBranch` is unchanged: Delhi POS B2C stays `delhi` / `INV-07671`.

GST split still uses seller GST state (from numbering location) vs commerce `place_of_supply_state`. MH customer + Delhi stock → `INV-671` + IGST.

---

## 3. Serial-first invoice contract

Fail closed when:

- state is not `SERIALS_ALLOCATED`
- allocated list is empty
- serial count ≠ physical merchandise qty
- duplicate serials (case-insensitive)
- blank serials
- no fulfilment branch

The invoice layer receives `allocatedSerialNumbers()`. After issuance the list is copied to `metadata.invoice_serials` and must not be treated as mutable (P4 must refuse re-allocation after `INVOICE_ISSUED`).

Bundled RD: description annotation `(bundled RD #{id})` on the hardware line. A second **priced** SAC line fail-closes. No invented amount.

---

## 4. PDF / Annexure A

Same `statutory-invoices/{id}.pdf`. Not a second statutory document.

| Serials | Rendering |
|---------|-----------|
| 1–5 | `Serial numbers: …` on the invoice page |
| >5 | `Serial Numbers: See Annexure A` plus later pages titled `ANNEXURE A` listing every serial |

Annexure header includes invoice number, `RDE*` order id, statutory identity, fulfilment id, total count. Multi-page `/Count` for 100–200 serials. Deterministic order is line_no / position. No truncation of the list (one serial per line).

POS/service invoices with no serials stay single-page invoice text (pagination only if they overflow, instead of the old drop-at-bottom-of-page).

---

## 5. IRN boundary

Reuse `queueEinvoiceIfEligible`. B2C skipped. B2B queued. Provider remains `none` / `NullEInvoiceGateway`. `worker_may_mint=false` so the processor does not submit. Live credentials are **UNKNOWN**. No Admin credential copy. No live HTTP.

---

## 6. Non-goals

Serial picker / stock moves (P4). Shiprocket (P5). Ingest enable. Cashfree correlate flag. Seven pending orders. Production deploy. POS/service issuer changes.

---

## 7. Remaining dependencies

| Phase | Needs |
|-------|-------|
| P4 | Implemented in P-07-09-18: Desk stock picker writes `allocated` serials. Live Owner P0-M1 map rows still required. |
| P5 | Shipment only after `INVOICE_ISSUED` + serials |
| P6 | Box callback |
| P7 | Observe ingest |
| P8 | New-order E2E, then seven one-by-one |

---

## 8. Classification

**VERIFIED:** `mint()` is the only allocator; `issueFromCommerceOrder` uses product/service matrix; POS Delhi B2C is `INV-07671`; GST split is seller state vs POS; unique statutory source key; Null IRN gateway.

**OWNER-LOCKED:** stock-location issuer; serial-first; annexure rule; `INV-*` only; seven frozen.

**INFERRED:** first hardware mint on an empty `delhi_b2c` sequence is `INV-671`; bundled RD is annotation only.

**UNKNOWN:** live IRN HTTP provider/credentials; SKU map; whether Owner wants a priced SAC split later (P0-M4).
