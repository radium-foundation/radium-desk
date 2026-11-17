# P-17-29 — Single inline assign performance acceptance (post-deploy)

**Do not** claim RD35299 (~34s) is fixed without production measurement.

## Preconditions

- Production **v4.1.83+** with footer **`v4.1.83`** after hard refresh.
- Admin session on **operations dashboard** (not order workspace unless testing non-`incident_id` path).
- **No** real customer orders; use internal/test cases only if Owner provides IDs.

## Measurements

| Layer | How | Pass criterion (indicative) |
|-------|-----|-----------------------------|
| Browser | DevTools Network: `POST .../orders/{id}/transaction` | Response **&lt; 5s** under normal load (Owner threshold TBD) |
| Server | `laravel.log` gap before `service_statutory_invoice.workflow_issue_succeeded` | Statutory may remain; separate from defer proof |
| Commit | DB: `transaction_id` set before HTTP 200 | **Immediate** on response |
| Outbox | `outbox_events` type `assign_reference.batch_follow_up` pending → completed | Registered before response; completed via worker |
| Deferred KPI | UI: KPI strip updates within **~1–2s** after success toast | `scheduleHybridKpiReconcile` |
| WA/email | Worker logs `bulk_assign.batch.follow_up.*` / communication actions | **Pending** at response; channels process on worker — **not** required on HTTP thread |

## Safe operator steps

1. Open dashboard; confirm version footer **v4.1.83**.
2. On **non-production** or Owner-approved sandbox case only: inline save reference once.
3. Record Network timing + confirm `extensions.operational_follow_up` in JSON; `kpi_strip_html` **null**.
4. Confirm row remove/reconcile without assigning references on live customer backlog unless explicitly authorized.

## Evidence to archive

- HAR or Network screenshot (no PII in report).
- Redacted JSON response snippet showing `operational_follow_up`.
- Worker log lines showing follow-up job phases (no message bodies).