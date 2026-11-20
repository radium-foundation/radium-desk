# P-20-18 — Investigation: invoice `23271` / `RB1921`

| Field | Value |
| --- | --- |
| Task | **P-20-18** |
| Cursor ledger | `bd0ac5bd-88f0-48e0-aacd-66eca9be9aef` |
| Desk ledger row | **RadiumDesk-P-20-18** |
| Risk | **HIGH** |
| Start (IST) | **2026-11-20 10:10:32** |
| End (IST) | **2026-11-20 10:12:03** |
| Duration | **~1 min 31 s** |

## Verdict

**PASSED** (read-only investigation) — Invoice creation is **explained**. **Not** caused by P-20-17 GST tinker or buyer-GST verification flag. **Routine automatic service statutory issuance** for `radiumbox_com` at workflow triggers.

---

## Mandatory Q&A (summary)

| # | Question | Answer class |
| --- | --- | --- |
| 1 | Which process created `23271`? | **VERIFIED** — `ServiceStatutoryInvoiceIssuanceCoordinator` → `StatutoryInvoiceService::issueFromSupportOrder` → `issueFromCommerceOrder` |
| 2 | What triggered it? | **VERIFIED** — workflow triggers `service_case_closed` and `service_reference_completed` on support order **75057** (`RB1921`) |
| 3 | Did Stage A cause it? | **INFERRED** — **coincidence** with worker restart; **not** GST flag logic; no provider path on blank GSTIN |
| 4 | Expected under prod behaviour? | **VERIFIED** — `service_statutory_invoice.auto_issuance.channel_defaults.radiumbox_com` = **allow_automatic** |
| 5 | Validly linked to commerce **24233**? | **VERIFIED** — `source_id` **RB1921**, `support_order_id` **75057**, single invoice |
| 6 | GST / POS / tax correct for B2C blank GSTIN? | **VERIFIED** (metadata) — blank `buyer_gstin`, POS **Uttarakhand (05)**, IGST **109.37**, value **717.00** |
| 7 | Duplicate invoice? | **VERIFIED** — **1** row for **RB1921** |
| 8 | IRN / receipt? | **VERIFIED** — e-invoice **skipped**, no IRN; receipt publication **awaiting_activation** (not allowlist-delivered) |
| 9 | Live GST provider? | **VERIFIED** — **none** for this order (no buyer GSTIN; **0** verification rows) |
| 10 | Safeguard? | See **Recommendations** — production mint moratorium during controlled GST journeys |

---

## Production identity (unchanged by this task)

| Item | Value |
| --- | --- |
| Release | **v4.1.102** / **`339e3dc99`** |
| GST verification | **ON** |
| GST fallback | **OFF** |
| Recovery backup | **`20261120T041822Z`** (not re-verified this task) |

---

## Invoice `23271` (metadata only)

| Field | Value |
| --- | --- |
| Number | **INV-6720760** |
| Status | **issued** |
| Channel | **radiumbox_com** |
| Source | **commerce_order** / **RB1921** |
| Issued at | **2026-11-20 10:02:39 IST** |
| `issued_by` | User **id 3** (workflow actor, not tinker) |
| Buyer GSTIN | **blank** (native B2C path) |
| Place of supply | **Uttarakhand / 05** |
| Seller GSTIN prefix | **07** (Delhi) |
| Tax | IGST **109.37**; CGST/SGST **0** |
| Lines | **2** × SAC **998313** (service) |
| Idempotency key | `statutory:radiumbox_com:commerce_order:RB1921` |

---

## Order linkage

| Entity | Id | Notes |
| --- | ---: | --- |
| Commerce order | **24233** | Created/updated **10:02:39 IST**; status **invoiced** |
| Support order (Desk) | **75057** | **RB1921**; created **2026-11-19**; updated **10:02:39 IST** |
| Hardware fulfilment | — | **None** (not hardware path) |
| GST verification rows (24233) | **0** | |
| B2C conversion rows | **0** | |

---

## Causal chain (VERIFIED)

```
ServiceCaseStatusService / OrderTransactionService (workflow)
  → ServiceStatutoryInvoiceIssuanceCoordinator::attempt()
  → StatutoryInvoiceService::issueFromSupportOrder(RB1921)
  → RadiumBox service commerce ensure + issueFromCommerceOrder()
  → Statutory invoice 23271 + commerce order 24233 materialised
```

**Log evidence (no customer payload in report):**

- `[2026-11-20 10:02:39] service_statutory_invoice.workflow_issue_succeeded` ×2  
  - `order_pk=75057`, `order_id=RB1921`  
  - `trigger=service_case_closed`  
  - `trigger=service_reference_completed`  
- Cashfree **PAYMENT_SUCCESS** for **RB1921** earlier (**~03:52 IST**) — payment precedes mint by hours.

**Outbox:** No pending events in ±3 min SQL window at investigation time (**INFERRED** immediate mint path succeeded synchronously).

---

## P-20-17 correlation (INFERRED / VERIFIED)

| Event (IST) | Finding |
| --- | --- |
| **10:02:18–27** Stage A `config:cache` + queue worker restart | **VERIFIED** |
| **10:02:39** Invoice + commerce order timestamps | **VERIFIED** |
| **~10:02:40+** P-20-17 tinker `blockingReasonsForOrder` on **24233** | **VERIFIED** — read-only gate; **does not mint** |
| P-20-17 selected **24233** as missing-GSTIN sample | **INFERRED** — selected **newest** paid order **after** auto-mint; **correlation ≠ causation** |

Stage A buyer-GST flag: **does not** enable channel ingest auto-mint (`channel_ingest.auto_issue_invoice` remains disabled by foundation guard). **VERIFIED** in code.

---

## External effects

| System | Result |
| --- | --- |
| WhiteBooks buyer GST lookup | **NOT RUN** (no GSTIN) |
| IRN | **skipped** (`e_invoice_records` for invoice **23271**) |
| Receipt publication | Row **414**, **awaiting_activation** (not expanded allowlist publish) |

---

## Policy impact

| Topic | Assessment |
| --- | --- |
| P-20-17 operator-mint boundary | **Not violated** — no operator `issueFromCommerceOrder` in P-20-17 session |
| Independent automatic issuance | **Permitted** — radiumbox.com service auto-issuance policy |
| Invoice correctness (B2C, no GSTIN) | **Consistent** with matrix #4 design; **not** a GST Stage A defect |
| Harmless automatic issuance | **Not claimed** — statutory and customer impact remain Owner/Finance scope |

---

## Recommendations (not implemented)

1. **Production mint moratorium** flag or ops runbook step during GST journey windows (freeze automatic service mint + reconciliation batch).  
2. P-20 journey probes: use **pre-agreed order IDs** or **synthetic UAT** only — never `orderByDesc('id')->first()` on production.  
3. Stage A observation: monitor `service_statutory_invoice.workflow_issue_*` alongside buyer-GST metrics.  
4. Optional: require `statutory_auto_issue_approved_at` for `radiumbox_com` (policy change — **Owner** only).

---

## Production mutations (this task)

**NO — Not performed** — invoice modification, flags, deploy, mint, provider calls, receipt expansion.

**Stage A flags unchanged during investigation.**

---

## Investigation steps

| Step | Result |
| --- | --- |
| VERIFY prod / flags | **PASSED** |
| INSPECT invoice / order | **PASSED** |
| TRACE logs / workflow | **PASSED** |
| CORRELATE timing | **PASSED** |
| Duplication check | **PASSED** |
| IRN / receipt read-only | **PASSED** |