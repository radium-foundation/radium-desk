# RadiumDesk-P-07-09-143 — Desk-local recovered-Commerce fulfilment authorization

**Verdict:** Implemented, tested, and ready to deploy. Production ingest/READY of the seven was **not** performed.

## Mechanism

Desk table `hardware_recovered_fulfilment_authorizations` records an explicit recovered-Commerce authorization:

`channel + source_type + source_id + commerce_order_id + commerce_order_no`

Eligibility:

```text
isFrozenForFulfilment =
  source is in FROZEN_SOURCE_IDS
  AND
  no matching active Desk authorization
```

`FROZEN_SOURCE_IDS` is unchanged. Channel ingest still uses `isFrozenSourceId()`, so a Box handoff replay cannot open HF.

Isolated recovered path:

```text
desk:fulfil-hardware {RDE} --step=ingest
```

with **no** `--payload` opens exactly one HF from the existing paid Commerce order when authorization matches. `--payload` on an authorized recovered source fails closed.

Authorize (no HF):

```text
desk:authorize-recovered-fulfilment --initial-seven --actor=RadiumDesk-P-07-09-143
```

or one pair: `desk:authorize-recovered-fulfilment {source_id} {commerce_order_no}`.

## Tests

`HardwareRecoveredFulfilmentAuthorizationTest` plus isolated / awaiting / operational classifier suites. Hardware Fulfilment PHPUnit previously 336 tests (1 skipped) before the 144 rebase; focused re-run after restore: 46 passed.

## Not done in this prompt

No `--step=ingest` / `--step=ready` against production seven. No serials, invoices, parcels, Shiprocket, or outbox.
