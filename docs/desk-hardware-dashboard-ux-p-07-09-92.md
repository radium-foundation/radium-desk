# Hardware Dashboard UX — P-07-09-92

Date: 2026-09-08

## Intent

Make `/dashboard?workspace=hardware` the operator entry for hardware work, using Services Dashboard chrome. Customer 360 is the context hub. Fulfilment show remains the execution surface. Package photo is one non-blocking evidence track.

## What changed

- Hardware workspace renders the existing Work Queue as a compact Services-style table: Order, Customer, Product, Serial, Status, Next action.
- Presentation queues: Ready, Exceptions, Pickup, Completed. Counts come from existing classifier stages; eligibility was not rewritten.
- Search uses already-loaded row fields (order, customer, serial, product). No extra joins or provider calls.
- Row click opens Customer 360 when an incident exists. Mutating next actions stay on the fulfilment show page and remain branch-scoped.
- Switching to/from Hardware is a full page load so live Services row merge cannot overwrite the hardware table.
- Customer 360 Hardware card is compact: order, payment, product, serial, stage, courier/AWB, one next action, Open Fulfilment, derived activity ticks.
- RIN copy: `Hardware cannot start yet.` / `Verified RIN → Desk hardware mapping is required.` No mapper.
- Show page stepper is 10 steps (Packing removed as a blocking step). Current step gets the caption and primary action.
- One Package Photo upload. Label-applied upload is no longer shown. Existing evidence of either kind still counts as recorded.
- Request Pickup and Mark Ready for Pickup no longer require a package photo. Shipped still requires a package photo to close.

## What did not change

- No schema, migrate, ingest, mapper, bulk actions, or Shiprocket credential/API contract changes.
- No live pickup/create/AWB/label/manifest against RDE318421.
- Services workspace business logic untouched. Shared journey-tracker markup reused, not redesigned.
- Inventory Hardware Operations remains as a compatibility deep-link.

## Tests

- `tests/Feature/HardwareFulfilment` — 211 passed, 1 skipped
- Hardware unit tests + overflow presenter — 34 passed
- Pint on dirty PHP

## Deploy

Not performed.
