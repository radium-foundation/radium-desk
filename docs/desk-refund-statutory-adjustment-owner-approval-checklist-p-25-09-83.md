# Owner approval checklist — refund statutory adjustment

**Prompt ID:** RadiumDesk-P-25-09-83  
**Date:** 2026-09-27  
**Deployed release:** v4.0.151 / `7d565746`  
**Purpose:** Explicit YES/NO gates before controlled UAT and before general production enablement.

**Instructions:** Each gate defaults to **NOT APPROVED / PENDING**. Owner marks **APPROVED** only with initials, date, and evidence reference. Do not approve gates that require live provider proof until authorized UAT or provider verification has occurred.

---

## Provider verification gates

| # | Gate | Status | Evidence reference | Owner initial / date |
| --- | --- | --- | --- | --- |
| 1 | Provider **CANCEL** contract verified (live, refund-triggered B2B &lt;24h path)? | **NOT APPROVED / PENDING** | _UAT Scenario A payload or provider doc_ | |
| 2 | Provider **CRN/GENERATE** contract verified (live, refund-triggered B2B ≥24h path)? | **NOT APPROVED / PENDING** | _UAT Scenario B CN IRN ack_ | |

**Note:** Code-derived contract in `desk-statutory-irn-whitebooks-cancel-p-25-09-49.md` and PHPUnit fakes do **not** satisfy gates 1–2. P-25-09-57 CN IRN on INV-0767138 is historical production evidence for orchestrator CN path only — **not** refund-triggered proof.

---

## UAT preparation gates

| # | Gate | Status | Evidence reference | Owner initial / date |
| --- | --- | --- | --- | --- |
| 3 | Disposable **commerce/service** B2B test records approved (new records; POS excluded)? | **NOT APPROVED / PENDING** | _UAT plan packet_ | |
| 4 | Backup/snapshot confirmed before UAT window? | **NOT APPROVED / PENDING** | _Backup path + timestamp_ | |
| 5 | Protected invoice denylist confirmed (zero overlap with disposable records)? | **NOT APPROVED / PENDING** | _Denylist document_ | |
| 6 | CA/GST practitioner approval obtained? | **NOT APPROVED / PENDING** | _Written CA sign-off_ | |
| 7 | UAT operator assigned? | **NOT APPROVED / PENDING** | _Name + role_ | |
| 8 | Monitoring available (SQL, logs, scheduler, refund UI)? | **NOT APPROVED / PENDING** | _UAT runbook §9_ | |
| 9 | Reconciliation queries prepared? | **NOT APPROVED / PENDING** | _Saved SQL scripts_ | |
| 10 | Abort criteria accepted? | **NOT APPROVED / PENDING** | _UAT runbook §11_ | |
| 11 | Rollback limitations acknowledged (NIC irreversibility)? | **NOT APPROVED / PENDING** | _UAT runbook §13_ | |

---

## Authorization gates

| # | Gate | Status | Evidence reference | Owner initial / date |
| --- | --- | --- | --- | --- |
| 12 | Owner authorizes **controlled production UAT** execution? | **NOT APPROVED / PENDING** | _Signed UAT charter_ | |
| 13 | Owner authorizes **eventual general enablement** (`REFUNDS_STATUTORY_ADJUSTMENT_ENABLED=true` permanent)? | **NOT APPROVED / PENDING** | _Post-UAT reconciliation + gates 1–2 APPROVED_ | |

**Gate 13 requires:** Gates 1–2 **APPROVED** with live evidence, UAT reconciliation signed, enablement runbook reviewed.

---

## Current deployment state (read-only baseline — P-25-09-83)

| Item | Verified value |
| --- | --- |
| Production version | v4.0.151 |
| Build | 7d565746 |
| Feature flag | OFF (unset) |
| `refund_statutory_adjustments` rows | 0 |
| Verified sandbox | None |
| Provider endpoint | `https://api.whitebooks.in` (production) |

---

## Related documents

- `desk-refund-statutory-adjustment-controlled-uat-runbook-p-25-09-83.md`
- `desk-refund-statutory-adjustment-enablement-runbook-p-25-09-83.md`
