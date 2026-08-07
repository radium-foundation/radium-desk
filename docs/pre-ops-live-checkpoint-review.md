# Pre–ops/live checkpoint review

**Date:** 2026-08-07  
**Production HEAD:** `e1370d7` (matches local `HEAD`)  
**Objective:** Clean repository checkpoint before `/admin/operations/live` optimization  
**Canvas:** [`pre-ops-live-checkpoint-review.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/pre-ops-live-checkpoint-review.canvas.tsx)

**Constraint honored:** No application logic modified; no commits created.

---

## Verdict

Safe to checkpoint **everything** currently in the working tree. There are two completed workstreams plus investigation-only documentation. There is **no unfinished application code** for ops/live optimization — only the architecture investigation doc for that endpoint.

| Bucket | Count | Safe? |
|--------|------:|:-----:|
| A Documentation | 14 | Yes |
| B Tests | 1 | Yes |
| C Infrastructure / tooling | 4 | Yes |
| D Application code | 3 | Yes |
| Hold out (unfinished) | 0 | — |

---

## Completed vs unfinished application code

### Completed (not yet on production)

**1. DriverGuide batch chunking**

- Status in docs: **Implemented**
- Splits coalesced DriverGuide flush into ordered chunks via `DRIVERGUIDE_BATCH_SIZE` (default 20)
- Paired feature test covers chunk sizes, config override, retry isolation
- Not on `e1370d7` yet — completed local work awaiting commit/deploy, **not** WIP

Files: `AssignReferenceBatchCoalescer.php`, `config/communication_actions.php`, `.env.example`, `tests/Feature/DriverGuideBatchChunkingTest.php`, related docs

**2. Platform Health heartbeat untrack**

- Status in docs: **Fixed**
- Runtime JSON removed from Git; directory `.gitignore` stub; deploy pre-pull harden

Files: `storage/framework/platform-health/*`, `tools/commands/deploy.sh`, `tools/README.md`, investigation doc

### Unfinished

**None in the working tree.**

`docs/p0-operations-live-architecture-investigation.md` is investigate-only (no app/config changes). Safe to include in the docs commit as the baseline before starting optimization.

---

## Classification table

| File | Category | Safe to Commit? | Reason |
|------|----------|:---------------:|--------|
| `docs/error-spike-39-webhook-failures-investigation.md` | A Docs | Yes | Investigation complete; no app changes |
| `docs/litespeed-php-infra-audit.md` | A Docs | Yes | Infra audit; investigate-only |
| `docs/opcache-max-file-size-change-plan.md` | A Docs | Yes | Server change plan (already applied on host) |
| `docs/opcache-max-file-size-change-results.md` | A Docs | Yes | Server change results; verified |
| `docs/p0-dashboard-kpi-zero-regression-investigation.md` | A Docs | Yes | Root cause proven; no fix in tree |
| `docs/p0-driverguide-batch-chunking-investigation.md` | A Docs | Yes | Documents completed DriverGuide chunking |
| `docs/p0-http-cpu-spike-0950-investigation.md` | A Docs | Yes | Investigate-only; no code |
| `docs/p0-live-cpu-investigation.md` | A Docs | Yes | Investigate-only; no code |
| `docs/p0-lsphp-http-cpu-attribution-investigation.md` | A Docs | Yes | Investigate-only; no code |
| `docs/p0-operations-live-architecture-investigation.md` | A Docs | Yes | Architecture baseline before ops/live work; no app edits |
| `docs/p0-order-record-id-recovery-audit.md` | A Docs | Yes | Audit complete; no recovery/writes |
| `docs/p0-production-cpu-request-inventory.md` | A Docs | Yes | Updated for DriverGuide chunking benchmarks |
| `docs/platform-health-heartbeats-git-tracking-investigation.md` | A Docs | Yes | Documents heartbeat untrack fix (Fixed) |
| `docs/redis-migration-readiness-assessment.md` | A Docs | Yes | Assessment only; no migration/code |
| `tests/Feature/DriverGuideBatchChunkingTest.php` | B Tests | Yes | Covers completed chunking work |
| `tools/commands/deploy.sh` | C Infra | Yes | Pre-pull restore for tracked heartbeat path; complete |
| `tools/README.md` | C Infra | Yes | Deploy docs for heartbeat restore |
| `storage/framework/platform-health/.gitignore` | C Infra | Yes | Directory stub; ignore runtime files |
| `storage/framework/platform-health/platform-health-heartbeats.json` | C Infra | Yes | Delete from tracking (runtime artifact) |
| `app/Services/AssignReferenceBatchCoalescer.php` | D App | Yes | Completed DriverGuide chunk flush; not ops/live |
| `config/communication_actions.php` | D App | Yes | Adds `DRIVERGUIDE_BATCH_SIZE` config; complete |
| `.env.example` | D App | Yes | Documents `DRIVERGUIDE_BATCH_SIZE=20` |

---

## Recommended commit grouping

Reset mixed staging first if you want clean boundaries (some platform-health/deploy files are already staged with the investigation doc):

```bash
git restore --staged .
```

### Commit 1 — Documentation + tests

```bash
git add \
  docs/error-spike-39-webhook-failures-investigation.md \
  docs/litespeed-php-infra-audit.md \
  docs/opcache-max-file-size-change-plan.md \
  docs/opcache-max-file-size-change-results.md \
  docs/p0-dashboard-kpi-zero-regression-investigation.md \
  docs/p0-driverguide-batch-chunking-investigation.md \
  docs/p0-http-cpu-spike-0950-investigation.md \
  docs/p0-live-cpu-investigation.md \
  docs/p0-lsphp-http-cpu-attribution-investigation.md \
  docs/p0-operations-live-architecture-investigation.md \
  docs/p0-order-record-id-recovery-audit.md \
  docs/p0-production-cpu-request-inventory.md \
  docs/platform-health-heartbeats-git-tracking-investigation.md \
  docs/redis-migration-readiness-assessment.md \
  tests/Feature/DriverGuideBatchChunkingTest.php
```

Suggested message theme: chore(docs) — checkpoint P0 investigations + DriverGuide chunking tests

### Commit 2 — Infrastructure / tooling

```bash
git add \
  storage/framework/platform-health/.gitignore \
  storage/framework/platform-health/platform-health-heartbeats.json \
  tools/commands/deploy.sh \
  tools/README.md
```

Suggested message theme: fix(deploy) — untrack Platform Health heartbeats and harden pull

### Commit 3 — Application code

```bash
git add \
  app/Services/AssignReferenceBatchCoalescer.php \
  config/communication_actions.php \
  .env.example
```

Suggested message theme: perf(assign) — chunk DriverGuide batch jobs by DRIVERGUIDE_BATCH_SIZE

---

## Hold-out list

*(empty — nothing unfinished)*

---

## Notes

- This review report (`docs/pre-ops-live-checkpoint-review.md`) was produced during the review; include it in Commit 1 if you want the checkpoint documented in-repo.
- Canvases under `~/.cursor/projects/.../canvases/` are outside the git repo and are not part of these commits.
- No ops/live application optimization has started in this tree.
