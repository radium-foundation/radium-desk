# Recoverable provider-rejected shipment is not Dashboard Blocked — RadiumDesk-P-07-09-144

**Date:** 2026-09-09  
**Prompt ID:** `RadiumDesk-P-07-09-144`  
**Mode:** Classifier fix only. No Shiprocket create. No mutation of shipment 10, courier, invoice, serials, parcel, or address.

Ledger last used: **P-07-09-143**. This ticket: **P-07-09-144**.

---

## Root cause

`HardwareFulfilmentOperationalClassifier::fromFulfilment()` treated **any** filled `HardwareShipmentReadiness::providerRejection` as a permanent exception:

`if ($providerError && ! $ownerBlocked) { nextAction=View; statusLabel=Blocked; mutating=false }`

`inspect()` sets `providerRejection` for an unbound `failure_class=provider_rejected` row (`shipments.id=10`). The fulfilment show page already used `canCreate` for the Create button. The Dashboard/status chip used the classifier overlay, so HF15 stayed **Blocked / View** after a valid courier selection.

Exact predicate: `filled($ready->providerRejection)` — not `canCreate`, not owner freeze.

---

## Change

Only overlay Blocked when the provider rejection is **unrecoverable**: no `canCreate` / `canFetchCourierOptions` / `canSelectCourier` / `canAttachMeasuredParcel`, and serials+invoice are already present.

Recoverable historical failures follow `shipmentPrepAction()` (Create Shipment, Select Courier, Get Courier Options, Enter Package Dimensions). Frozen/HOLD/RIN/`blocked_until_authorized` still Blocked.

Shipment 10 is not rewritten.

---

## Tests

Classifier unit tests: HF15-like rejection + valid courier → Create Shipment; unrecoverable rejection → Blocked; expired options → Get Courier Options; options without selection → Select Courier; frozen/HOLD remain Blocked.

Focused Hardware Fulfilment PHPUnit including classifier/dashboard/shipment UI/work queue/courier: 62 passed.

Unrelated dirty `HardwareRinIngestTest` failure was not in this diff.

---

## Production note at deploy time

Courier options for HF15 expired **14:54:28 IST**. After this overlay, expected Dashboard next action is **Get Courier Options** (retryable), not Create, until options are fetched and a courier is selected again. Create remains available when `canCreate=true`.
