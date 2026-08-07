# P0 DriverGuide Batch Chunking

**Status:** Implemented  
**Date:** 2026-08-07  
**Canvas:** [`p0-driverguide-batch-chunking.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p0-driverguide-batch-chunking.canvas.tsx)

Related: [p0-live-cpu-investigation.md](./p0-live-cpu-investigation.md) · Phase 9 in [p0-production-cpu-request-inventory.md](./p0-production-cpu-request-inventory.md)

---

## Architecture

Batch Assign Reference still coalesces into ordered DriverGuide work, but **flush splits by configurable chunk size**:

```text
AssignReferenceBatchCoalescer::flushDriverGuides
  items = pending guides (assignment order)
  chunkSize = config(communication_actions.driver_installation_guide.batch_size)
            = env DRIVERGUIDE_BATCH_SIZE (default 20)
  foreach array_chunk(items, chunkSize) as chunk:
      dispatch SendServiceReferenceDriverGuideBatchJob(chunk, actorId)
```

Per-order path inside each job is unchanged:

```text
BatchJob::handle
  → ReferenceNumberCommunicationService::handleServiceReferenceAssigned
    → WhatsApp (sync Interakt) + Email (SMTP)
    → service_reference.driver_guide_sent idempotency audit
```

Single-assign still uses `SendServiceReferenceDriverGuideJob` (not chunked).

### Guarantees preserved

| Guarantee | How |
|-----------|-----|
| Ordering | Chunks dispatched in assignment order; single worker FIFO on `notifications` |
| Idempotency | Existing `service_reference.driver_guide_sent` + `idempotency_key` |
| Retries | Each chunk job has its own `$tries` / `$backoff`; failure retries **only that chunk** |
| Already-sent | Idempotency skip — no WhatsApp/email resend on chunk retry |
| Audits / notifications / business logic | Unchanged executor path |

---

## Configuration

| Key | Source | Default |
|-----|--------|--------:|
| `DRIVERGUIDE_BATCH_SIZE` | `.env` | **20** |
| `communication_actions.driver_installation_guide.batch_size` | `config/communication_actions.php` | `max(1, (int) env(...))` |

Examples for **85** orders at default 20: jobs sized **20 / 20 / 20 / 20 / 5**.

---

## Production expectations

From production timings (~6.3s/order, sync WA+email):

| Batch size | Continuous hold (approx) | Jobs for 42 guides |
|-----------:|-------------------------:|-------------------:|
| 20 (default) | ~2.1 min / chunk | 3 (20+20+2) |
| 10 | ~1.0 min / chunk | 5 |
| mega-job (old) | ~4.0–4.5 min | 1 |

- **Total** Interakt+SMTP work on one worker: unchanged  
- **Peak** queue monopolization: −50–75% vs mega-job  
- Critical/RadiumBox jobs can interleave between chunks  

---

## Rollback

1. Set `DRIVERGUIDE_BATCH_SIZE=10000` (or any value ≥ largest batch) and redeploy / `config:clear` — restores single mega-job dispatch.  
2. Or revert `AssignReferenceBatchCoalescer::flushDriverGuides` + config/env.  
3. In-flight chunk jobs remain processable (same job class / payload shape).  
4. No migrations.

---

## Tests

`tests/Feature/DriverGuideBatchChunkingTest.php`:

- Dispatch shape for **1 / 19 / 20 / 21 / 42 / 85** orders (job count, sizes, order, no duplicates)  
- Config-driven size (batch_size=10 → 10/10/5 for 25)  
- Chunk retry isolation + idempotency (re-handle chunk does not resend; sibling chunk independent)

---

## Investigation notes (pre-implement)

Root cause of 4m28s spikes: sync Interakt (~4.6s) + SMTP (~1.5–2s) per order inside one job. Corrected live-CPU “85 orders” → **42 guides** for that job. Multi notification workers deferred (amplifies Hostinger CPU).
