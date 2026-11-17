# P-17-37 — Hardware dashboard performance UAT (post-deploy)

**Gate:** Required before treating **v4.1.85** as known-good for hardware dashboard progressive load and KPI strip.

## Preconditions

- Deployed build matches **`v4.1.85`** / `release.json` build from merge **#325** + release metadata commit.
- Production baseline before deploy was **`v4.1.84`** / **`c4ed21f90`**.
- `radium-desk-queue-worker` **RUNNING**.

## Functional checks (authenticated hardware operator)

1. **Needs Action / Awaiting Serial / Ready for Pickup / Shipping / Completed** — filters render rows; nav counts eventually match live SQL (not stuck at `…` indefinitely).
2. **Progressive page 1** — first rows appear before nav counts finish warming; page 1 completes without duplicate/missing rows (10 per page default).
3. **Search + workspace switch** — cancels idle prefetch; no stale rows from prior filter.
4. **KPI strip (hardware queue)** — no false literal `0` on first paint; values align with authoritative dashboard stats after `kpis_only` refresh.
5. **Permissions** — user without hardware visibility cannot access `/dashboard/hardware/rows` or `/dashboard/hardware/counts` (403).

## Performance evidence (Owner / ops)

Use methodology from **P-17-35** (OLS/access logs where available; authenticated browser network tab where credentials exist):

| Metric | Target (P-17-36) | Evidence source |
|--------|------------------|-----------------|
| First `hardware/rows` chunk (cold counts) | P95 &lt; 3s | Access log / Network timing |
| First visible row after navigation | &lt; 5s | Browser |
| Page 1 complete | &lt; 8s | Network (4 + 6 chunk pattern) |
| KPI accurate or loading | &lt; 2s | Network `live?kpis_only` |
| Page-2 prefetch | After page 1 + idle only | Network (no parallel page-2 during page 1) |

**SQLite fixture timings from CI/PHPUnit are not production evidence.**

Optional: inspect `Server-Timing` on `GET /dashboard/hardware/rows` (no customer PII in header).

## Sign-off

| Role | Pass / Fail | Notes |
|------|-------------|-------|
| Owner hardware ops | | |
| Release / deploy | | |