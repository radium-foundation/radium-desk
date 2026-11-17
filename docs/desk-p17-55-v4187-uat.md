# P-17-55 — v4.1.87 UAT (bulk hardware document download)

**Prerequisite:** Production deploy of **v4.1.87** with Owner authorization (not P-17-55).

## Hardware workspace (`?workspace=hardware`)

1. Select multiple rows on the **current page** (confirm checkboxes use fulfilment IDs, not order IDs only).
2. **Download labels** — ZIP contains one PDF per included order; reconciliation dialog shows included/excluded/failed counts.
3. **Download manifests** — duplicate manifest IDs deduped to one PDF in ZIP.
4. **Download package** — ZIP contains `labels/`, `manifests/`, `reconciliation.csv`.
5. Confirm **no** new labels/manifests appear in Shiprocket for orders that only had missing documents (download must not generate).
6. Confirm buttons are labeled as **download** (not generate).

## Regression spot-checks

- Single-order **Download label** / **Download manifest** from fulfilment show still work.
- **Generate label** / **Generate manifest** bulk actions (if exposed elsewhere) still invoke generation, not download routes.

## Sign-off

| Role | Result | Notes |
|------|--------|-------|
| Owner | | |