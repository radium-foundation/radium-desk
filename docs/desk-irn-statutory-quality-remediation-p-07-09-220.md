# RadiumDesk-P-07-09-220 — Statutory data quality + 45 B2B IRN remediation

## Permanent architecture

- **Service UQC:** `OTH` for configured RD Service / AMC (`SAC 998313`, `IsServc=Y`) via `service_sac` config and `ServiceStatutoryClassification`.
- **SAC policy:** New + remediated RD Service lines use `998313`; legacy `998314` accepted only for classification/remediation aliases.
- **Goods UQC:** Catalog/line snapshot only; no PCS/NOS default.
- **POS resolver:** `PlaceOfSupplyResolver` — goods use delivery/billing destination; services use transaction billing; GSTIN≠POS allowed when tax is consistent (`REVIEW`).
- **POS snapshot:** `place_of_supply_state_code`, `place_of_supply_source` on statutory invoice at mint.
- **Structured billing:** Mint copies `billing_address_structured`; IRN readiness uses invoice snapshot with commerce/POS fallback.
- **Address formatter:** `StatutoryAddressFormatter` — line1+line2 only (Loc/PIN separate), ≤200 combined, Addr1/Addr2 ≤100.
- **IRN readiness:** `EInvoiceInputReadiness` fail-closed before GENERATE; Customer 360 agent explanations via `EInvoiceAgentPresentation`.

## Remediation

- Command: `php artisan desk:statutory-remediate-exceptions`
- Controlled set: 45 invoice IDs from Gate 1 (P-07-09-219)
- SQL backup required before production mutation

## POS conflicts (verified)

| Invoice | GSTIN state | Evidence POS | Action |
|---------|-------------|--------------|--------|
| INV-076733 | 15 Mizoram | WB billing/POS, IGST consistent | Keep WB POS; Stcd from GSTIN; REVIEW |
| INV-076740 | 23 MP | Jharkhand billing/POS, IGST consistent | Keep Jharkhand POS; REVIEW |
| INV-076741 | 29 Karnataka | Rajasthan billing/POS, IGST consistent | Keep Rajasthan POS; REVIEW |
