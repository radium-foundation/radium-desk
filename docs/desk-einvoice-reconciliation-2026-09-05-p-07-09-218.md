# Read-only B2B IRN reconciliation 05 Sep 2026 → 10 Sep 2026 — P-07-09-218

**Prompt ID:** RadiumDesk-P-07-09-218  
**Production SELECT only at inventory time. No GENERATE during this inventory.**  
**Authoritative date:** `statutory_invoices.issued_at`  
**Range:** `2026-09-05 00:00:00` Asia/Kolkata through `2026-09-10 22:57:52`

## Totals

| Class | Count |
|-------|------:|
| Invoices examined | 1217 |
| B2B | 51 |
| B2C / not eligible | 1166 |
| Already IRN | 3 |
| B2B + eligible + mapper submittable + never submitted | 5 |
| B2B + missing statutory requirements | 43 |
| B2B + submitted/processing/ambiguous | 0 |
| Cancelled | 0 |
| Historical/excluded (before 05 Sep) | 0 |

B2B invoices requiring IRN = 3 already + 5 submittable + 43 incomplete = 51.  
Verified IRN before backfill = 3.  
Remaining unexpected missing IRN after backfill must be listed with reason.

## Already IRN

| ID | Number | Source | Ack No | Signed QR | Signed Invoice |
|----|--------|--------|--------|-----------|----------------|
| 818 | INV-076738 | RDE318388 | 172621148091774 | present | present |
| 1062 | INV-076746 | RDE318400 | 172621144003124 | present | present |
| 1123 | INV-076749 | POS-000002 | 172621145994081 | present | present |

## GENERATE-eligible (mapper gaps empty) — before Addr2 split

Oldest first. Get-IRN first. GENERATE only after 2154.

Addresses 101–200 characters need NIC Addr1+Addr2 mapping. Addresses over 200 cannot be issued without altering stored text.

| ID | Number | Source | Buyer GSTIN | Taxable | Tax | Total | Final |
|----|--------|--------|-------------|---------|-----|-------|-------|
| 629 | INV-076724 | RDE318516 | 29AAAJD1151D1ZS | 21177.97 | 3812.03 | 24990.00 | NOT ISSUED — buyer_address_exceeds_irp_limit (232 chars). GENERATE once → WhiteBooks 5002. No IRN. Not retried. |
| 645 | INV-076729 | RDE318503 | 24ALOPM5381R2Z6 | 2583.90 | 465.10 | 3049.00 | IRN issued after Addr2 split |
| 648 | INV-076730 | RDE318500 | 02ADZPT2982Q1ZD | 2117.80 | 381.20 | 2499.00 | IRN issued after Addr2 split |
| 793 | INV-076735 | RDE318517 | 29AAAJD1151D1ZS | 10588.98 | 1906.02 | 12495.00 | NOT ISSUED — buyer_address_exceeds_irp_limit (232 chars). GENERATE not attempted after 5002 lesson. |
| 795 | INV-076736 | RDE318490 | 36AADCO1540P1Z8 | 25830.51 | 4649.49 | 30480.00 | IRN issued after Addr2 split |

## NOT ISSUED — statutory data incomplete (do not invent values)

Skip reason on file: `worker_may_mint_off`. Eligibility is B2B. Mapper gaps block GENERATE.

| ID | Number | Source | Gaps |
|----|--------|--------|------|
| 79 | INV-07671 | RD3512613 | missing_buyer_pin, missing_buyer_loc, missing_uqc, missing_is_servc |
| 80 | INV-07672 | RD3512617 | missing_buyer_pin, missing_buyer_loc, missing_uqc, missing_is_servc |
| 84 | INV-07673 | RD3512599 | missing_buyer_pin, missing_buyer_loc, missing_uqc, missing_is_servc |
| 144 | INV-07674 | RD3512754 | missing_buyer_pin, missing_buyer_loc, missing_uqc, missing_is_servc |
| 157 | INV-07675 | RD3512789 | missing_uqc, missing_is_servc |
| 178 | INV-07676 | RD3512834 | missing_uqc, missing_is_servc |
| 356 | INV-07677 | RD3513280 | missing_uqc |
| 385 | INV-07678 | RD3513291 | missing_uqc |
| 417 | INV-07679 | RD3513390 | missing_uqc |
| 420 | INV-076710 | RD3513399 | missing_uqc |
| 444 | INV-076711 | RD3513444 | missing_uqc |
| 460 | INV-076712 | RD3513495 | missing_uqc |
| 472 | INV-076713 | RD3513524 | missing_uqc |
| 525 | INV-076714 | RD3513615 | missing_uqc |
| 550 | INV-076715 | RD3513691 | missing_uqc |
| 583 | INV-076716 | RD3513766 | missing_uqc |
| 604 | INV-076717 | RD3513814 | missing_uqc |
| 609 | INV-076718 | RD3513797 | missing_uqc |
| 616 | INV-076719 | RD3513823 | missing_uqc |
| 618 | INV-076720 | RD3513828 | missing_uqc |
| 621 | INV-076721 | RD3513831 | missing_uqc |
| 623 | INV-076722 | RD3513836 | missing_uqc |
| 624 | INV-076723 | RD3513842 | missing_uqc |
| 631 | INV-076725 | RD3513847 | missing_uqc |
| 636 | INV-076726 | RD3513856 | missing_uqc |
| 637 | INV-076727 | RD3513861 | missing_uqc |
| 638 | INV-076728 | RD3513865 | missing_uqc |
| 684 | INV-076731 | RD3513945 | missing_uqc |
| 693 | INV-076732 | RD3513972 | missing_uqc |
| 717 | INV-076733 | RD3513991 | buyer_state_mismatch, missing_uqc |
| 738 | INV-076734 | RD3514060 | missing_uqc |
| 805 | INV-276732 | RD3514191 | missing_uqc |
| 808 | INV-076737 | RD3514204 | missing_uqc |
| 835 | INV-076739 | RD3514229 | missing_uqc |
| 901 | INV-076740 | RDE318552 | buyer_state_mismatch |
| 957 | INV-076741 | RD122 | buyer_state_mismatch, missing_uqc |
| 1027 | INV-076742 | RD250 | missing_uqc |
| 1028 | INV-076743 | RD251 | missing_uqc |
| 1050 | INV-076744 | RD297 | missing_uqc |
| 1052 | INV-076745 | RD301 | missing_uqc |
| 1089 | INV-076747 | RD385 | missing_uqc |
| 1091 | INV-076748 | RD391 | missing_uqc |
| 1131 | INV-276744 | RD473 | missing_uqc |

Gap histogram: `missing_uqc` 34; PIN/loc/UQC/IsServc 4; UQC+IsServc 2; buyer_state_mismatch+UQC 2; buyer_state_mismatch 1; buyer_address_exceeds_irp_limit 2 (INV-076724, INV-076735).

## After backfill (2026-09-10 23:22 IST)

B2B with verified IRN: 6 (3 pre-existing + 3 new). Get-IRN recoveries of a previously missing IRN: 0. Remaining B2B without IRN: 45, all with mapper gaps listed above. Remaining **unexpected** missing IRN among mapper-submittable B2B: **0**.
