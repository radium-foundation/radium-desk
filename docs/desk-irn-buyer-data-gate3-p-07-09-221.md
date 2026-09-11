# RadiumDesk-P-07-09-221 — Gate 3 buyer data correction + POS resolver audit

## Scope

Controlled investigation of the five remaining B2B IRN exceptions after P-220 remediation. No buyer statutory data was rewritten without authoritative evidence.

## Five-invoice investigation (production)

| Invoice | Provider | Finding | Disposition |
|---------|----------|---------|-------------|
| INV-076715 | 3028 invalid GSTIN | Desk checksum passes; billing state matches GSTIN state (Bihar). No alternate GSTIN in order source. | **Blocked** — buyer GST registration verification required |
| INV-076733 | 3028 invalid GSTIN | Billing/POS West Bengal; GSTIN registration state Mizoram. IGST consistent with interstate POS. GSTIN rejected at NIC. | **Blocked** — buyer GST registration verification required |
| INV-076740 | 3039 PIN/state | MP GSTIN; Jharkhand billing/delivery/POS; PIN belongs to Jharkhand not MP registration state. | **Blocked** — buyer must supply GST-registration address PIN or correct GSTIN |
| INV-076741 | 3039 PIN/state | Karnataka GSTIN; Rajasthan billing/POS; PIN belongs to Rajasthan not Karnataka registration state. | **Blocked** — buyer must supply GST-registration address PIN or correct GSTIN |
| INV-076743 | 3038 PIN missing | Only source record contains the same non-existent PIN; no alternate authoritative address. | **Blocked** — buyer PIN verification required |

No production statutory/buyer snapshot mutations were performed.

## Original 45 reconciliation

| Bucket | Count |
|--------|------:|
| Original exception set | 45 |
| Already issued/recovered (after P-220) | 40 |
| Newly generated (Gate 3) | 0 |
| Still blocked | 5 |
| Unexpected/unclassified | 0 |

Production IRN count after Gate 3: **46** (unchanged).

## POS resolver audit

- **Goods B2B/B2C:** POS from delivery destination (`goods_delivery_destination`); issued invoices now prefer commerce `shipping_address_structured` when present.
- **Services:** POS from transaction billing address (`transaction_billing_address`).
- **GSTIN vs POS:** Stored separately; resolver does not derive POS from GSTIN. GSTIN≠POS allowed as `REVIEW` when tax is consistent.
- **Mint path:** Goods still use billing structured on mint request (shipping not on mint DTO); issued re-resolution uses shipping when linked.
- **Fail-closed:** Missing POS evidence → `place_of_supply_unresolved` / blocked classification.

## Prevention (code)

- `buyer_pin_gstin_state_mismatch` — billing structured state ≠ GSTIN registration state (NIC 3039 class).
- `EInvoiceProviderSubmissionBlocker` — maps persisted 3028/3038/3039 to agent-safe reasons.
- Customer 360 — readiness/provider blockers shown before generic permanent-failure message.
- Mint gate — `assertB2bInputReadinessForMint` rejects GSTIN/billing state PIN mismatch.

## Tests

402 statutory unit+feature tests PASS (includes POS matrix + provider blocker + PIN/GSTIN mismatch).
