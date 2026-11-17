# P-17-21 — Hardware workspace progressive rows + page cache

## P-17-20 overlap

- **P-17-20** merged at `0aae38e2f` (stuck KPI SWR). **No shared runtime paths** with hardware row loading; worktree based on merged `main`.

## Verified architecture (pre-change)

| Layer | Reference |
|-------|-----------|
| Route | `GET /dashboard?workspace=hardware` → `DashboardController` + `HardwareDashboardWorkspace::shell()` |
| Rows API | `GET /dashboard/hardware/rows` → `DashboardHardwareController::rows()` |
| Data | `HardwareFulfilmentWorkQueue::dashboard()` → `HardwareNeedsActionSqlQuery::*Page()` → `hydratePage()` |
| Client | `hardware-dashboard-async.js` (deferred shell, single JSON page load) |
| Progressive paging | `hw_progressive=1`, first page 10 rows / later 40 (`HardwareDashboardPageSizer`) |
| Ably | Hardware **table** does not subscribe to row events; stuck KPI uses `HardwareStuckOrdersUpdated` (P-17-20). `GET /dashboard/live/hardware` unchanged. |

### Bottleneck (VERIFIED)

- Client replaced the **entire tbody** only after one full page JSON response (all hydrated rows + counts + pagination HTML).
- First paint showed **skeleton rows only** until the complete page payload returned.
- Pagination **re-fetched** every time (no in-memory page cache); page 2+ waited on full round-trip.

## Implemented strategy

Bounded **SQL chunk** requests (not fake React Suspense streaming):

1. `hw_chunk_limit` + `hw_chunk_offset` apply additional LIMIT/OFFSET on the existing page slice via `HardwareDashboardRowChunkResolver`.
2. Client loads page 1 in chunks (default **4** then **3** rows) with `hw_skip_counts=1` on follow-up chunks.
3. **In-memory SWR page cache** (`hardware-dashboard-page-cache.js`) keyed by scope/filter/search/page; instant back navigation; **prefetch page N+1** after successful load.

## API

| Parameter | Purpose |
|-----------|---------|
| `hw_chunk_limit` / `hw_chunk_offset` | Within-page progressive rows |
| `hw_skip_counts=1` | Skip filter/scope aggregate SQL on chunk follow-ups |
| `chunk` JSON meta | `complete`, `next_offset`, `page_row_total`, `chunk_row_count` |

## Config

- `DASHBOARD_HARDWARE_ROWS_FIRST_CHUNK` (default 4)
- `DASHBOARD_HARDWARE_ROWS_CHUNK_SIZE` (default 3)
- `DASHBOARD_HARDWARE_ROWS_PAGE_CACHE_MAX` (default 6)

## Production deployment

**NO** — merge to main only; deploy gate not executed.