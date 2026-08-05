# Recent Activity — Service Case Reference Format

## Verdict

**Official Desk display format is `SC00001`** (`SC` + sequence zero-padded to 5 digits, no hyphen).

`SC1` is not a display format. It is only accepted as a **lookup/input variant** (and was a stale Recent Activity test expectation).

## Canonical rules

| Layer | Source of truth | Example |
| --- | --- | --- |
| Allocation | `IncidentReferenceService::formatReference()` | `SC00001` |
| Display | `Incident::formatDisplayReference()` / `display_reference` | `SC00001` |
| Stored modern | `incidents.reference_no` | `SC00001` |
| Stored legacy | `incidents.reference_no` | `SC-00001` → displays as `SC00001` |
| Lookup variants | `Incident::referenceMatchVariants()` / `parseReferenceSequence()` | `SC1`, `SC00001`, `SC-00001`, `1`, … |

Do **not** invent references from `incidents.id` (`'SC'.$id`). Sequence and primary key are not guaranteed to match.

## Surface audit

| Surface | What it shows / sends | Format |
| --- | --- | --- |
| Customer360 | `incident->display_reference` (ops header, device card, inquiry case ref) | `SC00001` |
| Dashboard (service cases / refunds rows) | `display_reference` in listings; JS fixtures use `SC00001` | `SC00001` |
| Recent Activity presenter | `display_reference` via resolved incident | `SC00001` |
| Timeline / Workforce activity | `display_reference ?: reference_no` | `SC00001` (legacy stored form normalized on display) |
| IRA | Telegram / assignment payloads use stored `reference_no` (modern = padded) | `SC00001` |
| Notifications | Message text uses stored `reference_no` | `SC00001` when allocated by sequence service |
| Email Intake / Quick create | Session + Customer360 open use `display_reference` | `SC00001` |
| Refunds workspace | Case column uses `$refund->incident->display_reference` | `SC00001` |

## Recent Activity finding

`RecentActivityPresenterTest::test_entity_incident_id_is_set_when_incident_reference_is_shown_without_loaded_model` expected `'SC'.$incident->id` (`SC1`).

That path still resolves the incident by `auditable_id` (`TeamActivityIncidentResolver::findIncident`) and correctly returns `display_reference` (`SC00001`).

### Changes made

1. **Stale expectation updated** to assert `display_reference` / `SC00001`.
2. **Presenter cleanup**: removed `'SC'.$incident->id` and `'SC'.$auditLog->auditable_id` fallbacks. If no resolved incident with a real reference exists, the presenter returns `null` rather than fabricating an unpadded id-based label.

## Rule of thumb

- **Show**: `display_reference` (or `Incident::formatDisplayReference($sequence)`).
- **Store/allocate**: `IncidentReferenceService::generate()` / `formatReference()`.
- **Search/match**: accept unpadded / hyphenated variants; never treat them as the official UI string.
