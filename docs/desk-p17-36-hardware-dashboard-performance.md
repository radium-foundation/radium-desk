# P-17-36 — Hardware dashboard performance (P-17-35 follow-up)

## Scope

- **A** Deferred cold aggregate SQL on progressive first chunk (SWR presentation + background job + `/dashboard/hardware/counts`).
- **B** Page 1: 4-row first paint + single remainder chunk (not 3+3).
- **C** Page 2 prefetch via `requestIdleCallback` after page 1 complete; cancelled on navigation/search.
- **D** Hardware KPI strip: pending SSR (`—`), `kpis_only` uses `data-kpi-live-workspace=action_required`.
- **E** `Server-Timing` on `GET /dashboard/hardware/rows`.

## Production deployment

**NO** — implementation merged **#325** @ **`2cd08018a`**. Release metadata **v4.1.85**: **P-17-37** (`docs/desk-p17-37-v4185-pre-deploy-gate.md`).

## Regression evidence (local, 2026-11-17)

| Check | Result | Exit |
| --- | --- | ---: |
| PHPUnit `HardwareDashboardCountsDeferTest` + chunk + resolver defer | PASS (5) | 0 |
| PHPUnit progressive + counts cache regression | PASS (10) | 0 |
| PHPUnit async shell + chunk + stuck SWR | PASS (10) | 0 |
| Vitest `dashboard-live-query` + `hardware-dashboard-async` | 12/12 PASS | 0 |
| `npm run build` | PASS | 0 |
| Laravel Pint `--dirty` | PASS | 0 |
| Full PHPUnit | NOT RUN | — |
| Production P95 targets (P-17-35) | NOT VERIFIED | — |

Local harness (progressive loading test): hardware shell ~392ms; rows API ~31ms on SQLite fixture — **not** production-equivalent.