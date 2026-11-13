# IRN catalog UQC assignment PCS — P-07-09-187

> **P-13-25 recovery preamble (2026-11-13)** — This file is a **documentation-only** restoration for PHPUnit row **227** / `test_p187_*`. The body below is the **2026-09-10 historical assignment narrative** from **RadiumDesk-P-07-09-187** (archival copy; source worktree `radium-desk-irn-foundation` @ `c86102e6`).
>
> **Critical — not re-authorization:** P-13-25 **does not** certify that an Owner approved PCS for `radium-desk` `main` today, **does not** instruct production SQL, and **does not** change repository catalog seeds or live data. Claims of **Owner decision** and **production `UPDATE`** below are **historical assertions from 2026-09-10** only; they were **not independently re-verified** in P-13-25 (production access: **NO**).
>
> **Current safe behaviour on `origin/main` (P-13-24, verified):** Application code does not assign PCS/NOS; missing UQC remains `missing_uqc`; stored `PCS` is accepted when explicitly present. **No new catalog assignment is authorized by this recovery stage.**

---

## Historical record body (2026-09-10)

Present-tense phrases below—including **Owner decision**, **this prompt assigns**, and production **UPDATE** language—describe the historical **RadiumDesk-P-07-09-187** record only. They are **not** current authorization for `radium-desk` `main`, catalog mutation, or production operations as of P-13-27.

**Project:** Radium Desk  
**Prompt ID:** RadiumDesk-P-07-09-187  
**Date:** 2026-09-10  
**Repository:** `/Users/ravi/RadiumWebsites/radium-desk-irn-foundation`  
**Branch:** `feat/irn-foundation-phase-a`  
**HEAD at inspect:** `c86102e6` (P-186 NULL gate)  
**Production:** KVM8 `srv1910783` `/var/www/radium-desk` DB `radium_desk`

**Verdict: 9 of 9 priority B2B hardware SKUs assigned `PCS`. 0 remaining NULL among the nine.**

Owner decision: these products are sold as individual serialized physical devices; **PCS (Pieces)** is the selected NIC UQC. `NOS` was not assigned. Services were not assigned.

Catalog mutation only. No invoice mutation. IRN remains OFF. Application code was not deployed.

## Authority

| Source | Role |
|--------|------|
| Owner decision (RadiumDesk-P-07-09-187, historical) | sales unit = individual devices; UQC = `PCS` |
| P-186 | nine SKUs identified; all `uqc` NULL; no stored sales-unit field |
| P-185 `EInvoiceUqcMapper` | `PCS` accepted; missing values still fail closed; no pcs/NOS default |
| P-181 NIC UQC master | `PCS` is on the reconciled master (`GGK`/`MLT`/`PCS` added; `GGR` not on master) |

Mapper acceptance alone was not used as assignment proof in P-186. This prompt assigns `PCS` because the owner selected it for these hardware SKUs.

## IRN safety (production, before and after)

| Flag | Before | After |
|------|--------|-------|
| `STATUTORY_EINVOICE_PROVIDER` | `none` | `none` |
| Gateway | `NullEInvoiceGateway` | `NullEInvoiceGateway` |
| `worker_may_mint` | `false` | `false` |
| `auto_issue_on_pos_complete` | `false` | `false` |
| statutory invoices | 1061 | 1061 |
| `e_invoice_records` | 1061 | 1061 |
| `irn` filled | 0 | 0 |
| WhiteBooks this prompt | not called | not called |

## Pre-change state

All nine found. Identity (id + SKU + name) matched P-170/P-186. All `uqc` NULL. No unexpected stored UQC. Catalog `uqc_filled` 0/87. Other products with UQC: 0.

| ID | SKU | Name | HSN | uqc before |
|----|-----|------|-----|------------|
| 28 | RBMFS110L1 | Mantra MFS 110 L1 Single Fingerprint Biometric Scanner | 84716050 | NULL |
| 22 | RBIMSOE3L1 | Morpho MSO 1300 E3 RD L1 Single Fingerprint Biometric Device - Idemia with RD Service | 84716050 | NULL |
| 5 | RBUGR89GPS | RADIUM UGR86 89-NaviC UIDAI Approved GPS for AADHAAR | 85269190 | NULL |
| 2 | RBFM220UFP | Access FM220 USB L1 Single Fingerprint Scanner | 84716050 | NULL |
| 4 | RBUGR86GPS | RADIUM UGR 86 UIDAI Approved USB GPS Receiver for AADHAAR | 85269190 | NULL |
| 17 | RBFUTFS80H | Futronic FS80H USB 2.0 Biometric Single Fingerprint Scanner | 84716050 | NULL |
| 18 | RBFUTFS88H | Futronic FS88H Single Fingerprint USB Biometric Scanner | 84716050 | NULL |
| 27 | RBMFS100L0 | Mantra MFS 100 USB Single Fingerprint Biometric Scanner | 84716050 | NULL |
| 30 | RBMIS100IR | Mantra MIS100 V2 Single Iris Scanner Biometric Device | 84716050 | NULL |

`STOP_BEFORE_MUTATE=NO` — historical execution-gate transcript from 2026-09-10; **not** a current runbook flag or authorization.

## Mutation (archived instruction — execution prohibited; any future catalog change requires a separately reviewed statutory decision and an Owner-approved implementation plan)

**ARCHIVAL SQL — HISTORICAL RECORD ONLY — DO NOT EXECUTE. No current production mutation is authorized by this document.**

Transaction: nine single-row `UPDATE inventory_products SET uqc='PCS' WHERE id=? AND sku=? AND uqc IS NULL`. Each affected-row count was 1. Total rows changed: **9**.

HSN, GST, prices, names, serial flags, and activity flags were unchanged (non-UQC fingerprint match). Statutory invoice line `uqc` for these SKUs remained empty (historical invoices not backfilled).

## After (re-read)

| ID | SKU | uqc after |
|----|-----|-----------|
| 28 | RBMFS110L1 | PCS |
| 22 | RBIMSOE3L1 | PCS |
| 5 | RBUGR89GPS | PCS |
| 2 | RBFM220UFP | PCS |
| 4 | RBUGR86GPS | PCS |
| 17 | RBFUTFS80H | PCS |
| 18 | RBFUTFS88H | PCS |
| 27 | RBMFS100L0 | PCS |
| 30 | RBMIS100IR | PCS |

Counts:

- nine changed → `PCS`: **9**
- nine remaining NULL: **0**
- catalog `uqc_filled`: **9 / 87**
- products outside the nine with UQC: **0**

Assigned UQC: **PCS** (mapper-accepted NIC master code). Not `NOS`.

## Not performed

- WhiteBooks / NIC authenticate, GENERATE, Get-IRN, cancel: **NO**
- Provider / gateway / worker / auto-issue / `.env` change: **NO**
- Deploy / release / tag: **NO**
- Service / AMC UQC assignment: **NO**
- Historical invoice / snapshot backfill: **NO**
- Bulk catalog UQC: **NO**
