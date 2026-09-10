# IRN pre-generation payload audit — P-07-09-188

**Project:** Radium Desk  
**Prompt ID:** RadiumDesk-P-07-09-188  
**Date:** 2026-09-10  
**Repository:** `/Users/ravi/RadiumWebsites/radium-desk-irn-foundation`  
**Branch:** `feat/irn-foundation-phase-a`  
**HEAD at inspect:** `baefa705` (P-187 catalog `PCS`)  
**Production:** KVM8 `srv1910783` `/var/www/radium-desk` DB `radium_desk`

**Verdict: BLOCKED** — not `READY_FOR_CONTROLLED_GENERATE`.

Read-only. Mapper `map()` + `WhitebooksNicPayloadFactory::generateBody()` only. No WhiteBooks HTTP. No invoice/catalog/UQC/`.env` mutation. No deploy.

## Selection

Desk catalog SKUs `RBMFS110L1` etc. do **not** appear on B2B statutory lines. Box B2B invoices store channel model ids (`946`, `951`, `1723`). P-07-09-27 maps `946` → product 28 / `RBMFS110L1`.

The only statutory lines that store the nine catalog SKUs themselves are two **B2C** rdservice.in invoices (`INV-67750`, `INV-67751`, `buyer_gstin` NULL). Those were not used.

Eight issued B2B hardware invoices exist (single HSN 84716050/85269190 line, paid Cashfree, `e_invoice_records.status=skipped`, `irn` NULL). **INV-076746** was selected: paid, valid 15-char buyer GSTIN, one hardware line, no SAC `998313` line, structured billing present (needed for buyer PIN/LOC), maps to a P-187 `PCS` product.

| Field | Value |
|-------|--------|
| Invoice | `INV-076746` (id 1062) |
| Source | `RDE318400` / commerce 740 / `radiumbox_com` |
| Status | issued tax invoice / payment paid |
| Seller | Delhi `07AAICP1128M1Z9` / Phil Technologies (P) Limited |
| Buyer GSTIN | `21CHNPS8997L1Z7` (valid; state 21 Odisha) |
| POS | Odisha |
| Line SKU | `946` (Box model) |
| Catalog SKU | `RBMFS110L1` via `channel_sku_maps` |
| HSN | `84716050` |
| Qty | 2 |
| Line UQC | NULL |
| Catalog UQC | `PCS` |
| IRN | none |

Commerce line `rdserviceid=1119` / `amcid=1120` are Box bundle metadata. They are **not** separate statutory service lines. Description text includes `(bundled RD #1119)`.

## IRN safety (before and after)

| Flag | Value |
|------|--------|
| `STATUTORY_EINVOICE_PROVIDER` | `none` |
| Gateway | `NullEInvoiceGateway` |
| `worker_may_mint` | `false` |
| `auto_issue_on_pos_complete` | `false` |
| statutory invoices | 1062 |
| `e_invoice_records` | 1062 |
| `irn` filled | 0 |
| nine SKUs still `PCS` | 9 |
| WhiteBooks this prompt | not called |

Production overlay already contains `EInvoiceIrnPayloadMapper` + `WhitebooksNicPayloadFactory` (hashes match this worktree). Production `EInvoiceUqcMapper` is **older than P-185**: `PCS` is **not** in the live whitelist (`resolve('PCS')` → `unsupported_uqc`). Local committed mapper does accept `PCS`. Neither path was deployed by this prompt.

## Eligibility

`EInvoiceEligibility`: **eligible / `b2b_eligible`**. Stored GST header+lines complete and internally consistent.

## Mapped IRN payload (not sent)

`EInvoiceIrnPayloadMapper` on production invoice 1062. `isSubmittable()=false`. Factory therefore returns **null** — no GENERATE JSON would be posted.

```
supply_type: B2B
document: Typ=INV  No=INV-076746  Dt=10/09/2026
seller: Gstin=07AAICP1128M1Z9  LglNm=Phil Technologies (P) Limited
        Addr1=1312, Hemkunt Chambers, Nehru Place, New Delhi 110019
        Loc=New Delhi  Pin=110019  Stcd=07
buyer:  Gstin=21CHNPS8997L1Z7  LglNm=NABAJAT MAHALA
        Addr1=TARINI MARKET COMPLEX,, near power house, Bhadrak, Odisha, 756100
        Loc=Bhadrak  Pin=756100  Pos=21  Stcd=21
item:   SlNo=1  IsServc=N  HsnCd=84716050
        PrdDesc=Mantra MFS 100 / 110 L1 Fingerprint Scanner (bundled RD #1119)
        Qty=2  Unit=null
        UnitPrice=2549.00  AssAmt/TotAmt=4320.34  GstRt=18
        IgstAmt=777.66  CgstAmt=0  SgstAmt=0  TotItemVal=5098.00
values: AssVal=4320.34  CgstVal=0  SgstVal=0  IgstVal=777.66  TotInvVal=5098.00
gaps:   missing_uqc
```

Would-be NIC 1.1 keys if the gap check were bypassed: `Version`, `TranDtls`, `DocDtls`, `SellerDtls`, `BuyerDtls`, `ItemList`, `ValDtls`. Factory omits `EwbDtls`, `ExpDtls`, `PayDtls`, `VehDtls`, `ShipDtls`. `TranDtls.TaxSch=GST`, `SupTyp=B2B`, `RegRev=N`, `IgstOnIntra=N`.

## Check results

| Check | Result |
|-------|--------|
| `SupTyp=B2B` | pass |
| No dummy EWB/export/vehicle | pass (omitted; body not emitted) |
| Doc type/number/date `dd/mm/YYYY` | pass (`INV` / `INV-076746` / `10/09/2026`) |
| Seller Delhi GSTIN, name, address, loc, PIN, state | pass |
| Buyer GSTIN, name, address, city, PIN, POS/state 21 | pass |
| HSN / qty / description populated | pass |
| UQC exactly `PCS` | **FAIL** — line NULL; payload `Unit` null |
| No SAC/AMC statutory line | pass (one hardware line, `IsServc=N`) |
| Stored GST used, not rewritten | pass |
| Line/header GST and invoice total | pass: 4320.34 + 777.66 = 5098.00; 18% IGST matches interstate 07→21 |
| Seller/buyer state vs tax | pass (IGST, CGST/SGST 0) |
| GENERATE JSON produced | **FAIL** — `generateBody=null` |
| NIC `UnitPrice` vs `AssAmt/Qty` | **FAIL** — 2549.00 (GST-inclusive stored price) vs exclusive 2160.17 |

## Missing / invalid (do not fix in this prompt)

1. **`missing_uqc`** — `statutory_invoice_items.uqc` is NULL. GENERATE mapping uses the **stored line** only. Catalog `PCS` is not copied at payload build. P-187 did not backfill historical lines (correct for that prompt).
2. **`Unit` is not `PCS`** — payload unit is null. First controlled GENERATE must not proceed.
3. **Production mapper rejects `PCS`** — live overlay whitelist predates P-185. Even a future line `PCS` would fail on current production PHP until that mapper is deployed. Local branch accepts `PCS`. **NO — Not performed** (no deploy).
4. **NIC `UnitPrice` inconsistency** — factory would send `UnitPrice=2549` with `TotAmt`/`AssAmt=4320.34` and `Qty=2`. NIC 1.1 expects unit price in the same tax-exclusive basis as `TotAmt` (`AssAmt/Qty=2160.17`). Not in mapper `gaps[]`, but it would be invalid GENERATE arithmetic if the UQC gap were cleared.
5. **`generateBody=null`** — WhiteBooks GENERATE would not be called by the adapter (`irp_fields_incomplete`).

## Not performed

WhiteBooks authenticate / GENERATE / Get-IRN: **NO**  
Invoice, snapshot, catalog, UQC, order, `.env` changes: **NO**  
Provider / gateway / worker / auto-issue: **NO**  
Deploy / release / tag: **NO**
