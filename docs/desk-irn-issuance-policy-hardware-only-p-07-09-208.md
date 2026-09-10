# Phase A hardware-only automatic issuance policy — P-07-09-208

**Project:** Radium Desk  
**Prompt ID:** RadiumDesk-P-07-09-208  
**Date:** 2026-09-10  
**Before SHA:** `f47db07935bd62b38d6a8351f826b3f69aaadb6c`

Owner selected **Option A** from P-207: Phase A automatic GENERATE is hardware-only B2B. Automatic issuance itself remains **OFF**.

No production WhiteBooks calls. No deploy. No requeue of existing `worker_may_mint_off` records.

## Architecture

```text
Policy (HARDWARE_ONLY | ALL_ELIGIBLE_B2B)
  ↓
Eligibility (P-207 cancelled / tax-invoice / issued / scope / B2B GST)
  ↓
Hardware/service classification (invoice kind, not IsServc)
  ↓
Phase-A permission
  ↓
Existing IRN processor (P-206 recover-only)
  ↓
WhiteBooks (still unbound; NullEInvoiceGateway)
```

Two separate concepts:

| Concern | Class | Question |
|---|---|---|
| Statutory classification | `EInvoiceServiceClassification` | What is NIC `IsServc` for this line? |
| Issuance policy | `EInvoiceIssuancePolicy` + `EInvoiceIssuanceClassifier` | May this invoice automatically request an IRN under the current rollout? |

`IsServc` is not the policy decision.

## Policy modes

`EInvoiceIssuancePolicyMode`:

- `hardware_only` — source default, hardcoded in `config/statutory_invoices.php` (not an env toggle)
- `all_eligible_b2b` — implemented and testable; not enabled

Unknown config values fail closed to `hardware_only`.

## Hardware / service classification

Verified application evidence, not an HSN 84/85 substring:

| Kind | Evidence |
|---|---|
| Service | Configured `service_sac` SAC/SKU on `rdservice_in` / `rdservice_net`, **or** HSN/SAC chapter `99` |
| Hardware | Channel `desk_pos` or `radiumbox_com` **and** a non-empty HSN that is not classified as service |
| Mixed | At least one hardware line and one service line |
| Unknown | Empty lines, empty HSN on a hardware channel, or any other channel (e.g. `radiumsign_com`) |

Mixed and unknown **always fail closed**, including under Phase B.

## Automatic issuance matrix

| Type | Phase A `HARDWARE_ONLY` | Phase B `ALL_ELIGIBLE_B2B` |
|---|---|---|
| B2B hardware | allowed | allowed |
| B2B service | blocked `issuance_policy_service_excluded` | allowed |
| B2C hardware | blocked `b2c_not_eligible` | blocked |
| B2C service | blocked `b2c_not_eligible` | blocked |
| mixed | blocked `issuance_policy_mixed_lines` | blocked |
| unknown | blocked `issuance_policy_unknown` | blocked |

P-207 guards still run first: cancelled, not issued, not tax invoice, pre-2026-09-01, invalid GSTIN, incomplete GST.

## Outbox / backlog safety

- Policy is enforced in `EInvoiceEligibility`, which the processor re-evaluates before GENERATE.
- `queueEinvoiceIfEligible` does not promote an existing `skipped` record, including `worker_may_mint_off`.
- Processor `claim()` returns done when the e-invoice row is already `skipped`. Retry, restart, or switching source policy to `all_eligible_b2b` cannot GENERATE from a skipped row.
- A later Phase B rollout still needs an **explicit, separately approved backlog/requeue**. Changing the config key is not that decision.
- Processing / ambiguous rows still recover via Get-IRN only (P-206). Policy does not skip that path.

## Duplicate WhiteBooks GENERATE errors

Unchanged from P-207.

**Verified codes:** none  
**Mapped codes:** none (`VERIFIED_GENERATE_DUPLICATE_ERROR_CODES = []`)  
**Unverified:** production duplicate GENERATE envelope  
**Production probe:** NO — Not performed  
**NIC sandbox 2150:** not treated as WhiteBooks proof

## Production

```text
STATUTORY_EINVOICE_PROVIDER=none
Gateway=NullEInvoiceGateway
worker_may_mint=false
auto_issue_on_pos_complete=false
issuance_policy=hardware_only
```

**GENERATE / Get-IRN / Cancel / invoice creation / provider enablement / worker enablement / auto-issue / deploy:** NO — Not performed.
