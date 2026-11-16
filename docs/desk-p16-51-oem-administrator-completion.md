# P-16-51 — OEM administrator completion (Service Reference + RD subscription expiry)

## Summary

Replaces dashboard **Confirm result** / **Reject result** for captured OEM evidence with a two-field administrator form and single save control. OEM DOM capture remains informational only (`draft_payload` → `confirmed_payload` on save). Case closure uses existing `OrderTransactionService::assignTransactionId`.

## API

- `POST /dashboard/service-cases/{incident}/oem-handoff/sessions/{sessionPublicId}/complete-assignment`
- Body: `service_reference`, `subscription_expiry_date` (RD subscription domain — not OEM warranty expiry)
- Auth: `view` incident + `assignTransaction` on open orders; locked orders require `orders.update` so validation errors surface instead of silent 403.

## Persistence

- `OemHandoffAdministratorCompletionService` — row lock, `DraftReady` → `Confirmed`, atomic assign + `persistAdministratorProvidedExpiry` + audit `oem_handoff.administrator_completed`
- Confirm/reject routes remain for API compatibility but are not exposed in the dashboard OEM panel.

## UI

- `resources/js/dashboard-oem-warranty-handoff.js` — completion form mirrors inline REF cell styling; shares legacy verification guard with inline transaction save.
- Dashboard row/KPI refresh matches `orders.transaction.store` JSON (`remove_row`, `row_html`, `kpi_strip_html`).

## Deploy

**4.1.74** changelog entry only. **NO** production deploy, flags, or migrations in this prompt.