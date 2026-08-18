# P15-08-053 — Cutover freeze plan (read-only)

Canvas: [`/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p15-08-053-cutover-freeze-plan.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p15-08-053-cutover-freeze-plan.canvas.tsx)

Inspected 2026-08-15 from repo + read-only Hostinger probes. **No freeze, DNS, extract, transport, apply, or `deskd` was executed.**

Latest applied generation: `tier1-rehearsal-20260815-163004-a49da7`. VPS dark. Production DNS: `desk.radiumbox.com` → Hostinger `187.127.183.72`. VPS `148.113.8.82` must stay off DNS until after a frozen apply reconciles.

## Classification of this document

This is an **operational plan**, not authorization to freeze. Next action is a dedicated freeze-execution prompt.

## Architecture (what actually writes)

Hostinger production uses **two hPanel cron jobs** (not `crontab -l`; user crontab is empty). `QUEUE_WORKER_MODE=dedicated_cron`. Between minutes, `ps` shows no artisan (workers use `--stop-when-empty --max-time=55`).

| Entry | Command | Role |
|---|---|---|
| Cron #1 | `* * * * * /home/u215544208/laravel/radium-desk/bin/schedule-run.sh` → `php artisan schedule:run` | Heartbeat, `schedule:light-tick` (includes `outbox:process`, automation-pending, presence), Gmail sync, reminders, Cashfree auto-recover, RadiumBox recover, missing-serial, smart-assignment, snapshots, IRA/Telegram |
| Cron #2 | `php artisan queue:work database --queue=critical,notifications,default,maintenance --stop-when-empty --max-time=55 --tries=3 --sleep=1` | `RadiumBoxOrderEnrichmentJob` and other queued work |

**HTTP on Hostinger is the dominant Tier-1 writer** (observed +4 orders/incidents every few minutes while cron is already running). Pausing cron alone **does not freeze** Cashfree, Bonvoice, staff desk, or Gmail-created cases that enter via web.

Cashfree webhook (`POST /api/webhooks/cashfree`) **writes in the request**: `cashfree_webhook_logs` → `orders` + `incidents` + `reference_sequences.sc` → `OrderPaid` → `finance_journals` / `finance_journal_lines`. That matches the live drift shape.

Bonvoice webhook writes `bonvoice_webhook_logs` / events / links (Tier 1). Currently 0 drift; still freeze HTTP so a call during extract cannot break FK apply order.

Staff web (`TrackTeamMemberActivity` / case UI) updates `users` and can create/update `incidents` / `orders`.

## 1. Hostinger processes/jobs that WRITE Tier-1

Must treat as writers (table → source):

| Source | Tables |
|---|---|
| Cashfree HTTP webhook + `cashfree:auto-recover-missing` | `cashfree_webhook_logs`, `orders`, `incidents`, `reference_sequences.sc`, journals/lines |
| Desk / API HTTP | `orders`, `incidents`, `users`, finance, close outcomes, refunds, etc. |
| Bonvoice HTTP + outbox drain | `bonvoice_webhook_logs`, `bonvoice_call_events`, `incident_bonvoice_call_links`, `incidents` |
| `inbound-email:sync-gmail` → `IncomingEmailServiceCaseCreateService` | `incidents` (and related) |
| `queue:work` `RadiumBoxOrderEnrichmentJob` | `orders` (RadiumBox columns / `updated_at`) |
| `radiumbox:recover-sync` | `orders` |
| `missing-serial:process` | `orders` |
| `service-cases:process-deferred-smart-assignment` | `incidents` |
| `service-cases:process-automation-pending` (light-tick) | `incidents` |
| `ira:capture-memory-snapshot` | `ira_memories` (daily 00:05; not in evening window but Cron #1 still paused) |
| Presence / activity HTTP | `users` (`last_active_at`; staff actions also bump `updated_at`) |

## 2. Processes that MUST be paused for the freeze

All-or-nothing on Hostinger. Do **not** try to leave Cron #1 up “for heartbeat.”

1. **Disable hPanel Cron #1** (`bin/schedule-run.sh`).
2. **Disable hPanel Cron #2** (`queue:work`).
3. Wait ≤60s for in-flight `--max-time=55` processes to exit.
4. **`php artisan down`** on Hostinger (maintenance). This is what stops Cashfree/Bonvoice/desk HTTP writers. CLI extract from laptop still uses SSH + `remote_extract.php` and is unaffected.
5. Optional one-shot CLI: `queue:work … --stop-when-empty` to drain any jobs already in `jobs` (read-only check tonight: pending **0**).

Do **not** stop Hostinger MySQL. Do **not** change DNS yet. Do **not** enable VPS scheduler/queue yet.

## 3. READ-ONLY / can remain (with caveats)

| Item | During freeze | After DNS + VPS activation |
|---|---|---|
| VPS (dark) | Leave dark until after reconcile | Then start scheduler/queue |
| Hostinger MySQL | Stay up (extract source) | Stay up as leftover; do not write |
| Heartbeat / IRA digest / Telegram reminders / `infrastructure:metrics` | **Stop** because they share Cron #1 | Resume **on VPS only** |
| Gmail sync | **Stop** (creates incidents) | Resume **on VPS only** |
| `reminders:dispatch-due` | **Stop** (shares Cron #1; writes notification tables, not the current delta, but not worth a second cron) | Resume on VPS |
| Hostinger LiteSpeed serving `/up` or static | Maintenance 503 is intended | Hostinger should stay down or not receive DNS |

**Gmail, reminders, heartbeat cannot safely stay active on Hostinger during freeze.** Heartbeat is cache-only but Cron #1 also runs writers. Gmail creates Tier-1 incidents.

## 4. Verify Hostinger writes have stopped

After freeze, poll twice ~60s apart (read-only):

```bash
# Hostinger — max ids / sc / updated_at must not move
php artisan tinker --execute='...'  # or remote_inspect table-stats

# Must stay equal to previous sample:
# orders.max(id), incidents.max(id), cashfree.max(id),
# journals.max(id), journal_lines.max(id), sc.current_value,
# max(updated_at) on those tables
```

Also:

- `ps` has no `schedule:run` / `queue:work` / `outbox:process` / `sync-gmail` / `cashfree:auto-recover` / `radiumbox:recover-sync`.
- `GET https://desk.radiumbox.com` → **503** (maintenance).
- `POST /api/webhooks/cashfree` → **503** (do not send a real payment; Cashfree retries are expected).

If any watermark moves: **abort freeze**, find the writer, do not extract.

## 5. Verify queue cannot modify Tier-1

```bash
# Hostinger
SELECT queue, COUNT(*) FROM jobs GROUP BY queue;   -- must be 0
ps aux | grep queue:work                           -- none
```

`failed_jobs` (currently **2**) are dead-letter; they do not run. Do not retry them during freeze.

After Cron #2 is disabled, one manual `--stop-when-empty` is enough if `jobs` was non-zero.

## 6. Resume after DNS cutover (VPS only)

**Do not re-enable Hostinger Cron #1/#2 or `artisan up` on Hostinger.** That would dual-write.

On **VPS**, after DNS points at `148.113.8.82` and Gate 3 passed while frozen:

1. Confirm `desk.radiumbox.com` A → `148.113.8.82` (not Hostinger).
2. `php artisan up` on VPS (if it was down; today VPS is dark with HTTP not serving the app as production).
3. Enable VPS scheduler + queue (Supervisor/cron equivalent of Cron #1/#2). This is when VPS leaves “dark.”
4. Confirm Cashfree webhook URL still `/api/webhooks/cashfree` on the new host; allow Cashfree retries to land on VPS.
5. Leave Hostinger in maintenance.

## 7. Gmail / reminders / heartbeat

| Job | Safe during freeze on Hostinger? | Why |
|---|---|---|
| `inbound-email:sync-gmail` | **No** | Creates service cases (`incidents`) |
| `reminders:dispatch-due` | No (practical) | Shares Cron #1; not the current +4 delta but cannot split cron |
| Scheduler heartbeat | No (practical) | Shares Cron #1; 15 min gap is acceptable |
| IRA/Telegram/watchdog | No (practical) | Same |

## 8. Cashfree webhook processing

**Must freeze.** It is the primary live writer of the remaining delta (orders + incidents + `sc` + journals + cashfree logs). Pausing `cashfree:auto-recover-missing` is **not enough**. HTTP webhook must 503 via `artisan down`. Do **not** turn off Cashfree in their dashboard if retries should replay after VPS activation (preferred). Dashboard pause is only if you accept dropped webhooks.

## 9. API / web traffic

**Must be temporarily disabled** (`artisan down` on Hostinger). Staff desk and webhooks are HTTP. A “please don’t click” freeze will leak `users`/`incidents` updates and new Cashfree payments.

## 10. Exact order

1. **Freeze Hostinger writes:** disable Cron #1, disable Cron #2, wait 60s, `artisan down`.
2. **Verify:** no artisan writers; `jobs=0`; watermarks stable 60–90s; desk 503.
3. **Final extract** from VPS checkpoints (same `TableDeltaExtractor` path as P15-08-050). Do not reuse `a49da7`.
4. **Transport-only** (`ExtractFileTransporter`).
5. **Apply** `DatabaseSyncApplyService::run(null, 1, <new-id>, true)` — skipExtract, no `db:sync-delta --apply`.
6. **Reconcile** read-only Gate 3 **while still frozen**. Required: `passed=true`, `sc` match, order/incident/cashfree/journal counts match, soft-deletes match.
7. **DNS cutover:** `desk.radiumbox.com` → `148.113.8.82`. Do not touch Hostinger data.
8. **VPS activation:** scheduler + queue + HTTP. VPS is no longer dark.
9. **Verification:** dark-status on Hostinger irrelevant; VPS serving; Cashfree retry; one more read-only count check.

## 11. Safest rollback point

**Before DNS.** Hostinger is still the only production origin.

| Failure | Action |
|---|---|
| Freeze verification fails (watermarks still moving) | Do not extract. Keep or lift freeze after fixing writers. DNS unchanged. |
| Extract/transport/checksum fail | Leave Hostinger frozen or unfreeze (ops choice). No apply. VPS checkpoints still `a49da7`. |
| Apply exception (FK/unique/checksum) | Chunk rolled back; that table’s checkpoint **not** advanced. **Unfreeze Hostinger** (`artisan up`, re-enable both crons). VPS stays dark. Resume later with skipExtract on the same gen or a new extract. |
| Apply OK but Gate 3 `passed=false` **while frozen** | **Do not DNS.** Treat as data/FK issue. Unfreeze Hostinger. VPS remains standby. |
| After DNS | Rollback is hard (split brain). Do not revert DNS to Hostinger if VPS already allocated `sc` or accepted webhooks. |

Unfreeze Hostinger (rollback to live source):

1. `php artisan up`
2. Re-enable Cron #2, then Cron #1
3. Confirm desk 200 and Cashfree 200
4. VPS remains dark; do not start VPS queue

## 12. Expected write-freeze duration

| Step | Time |
|---|---|
| Disable crons + drain in-flight | 1–2 min |
| `artisan down` + quiet verify | 2–3 min |
| Incremental extract (small, similar to `a49da7`) | 1–3 min |
| Transport | ~1 min |
| Apply + Gate 3 (orchestrator SSH gates dominate; last apply ~6.5 min) | 6–10 min |
| DNS TTL / cutover | 1–5 min (depends on `desk.radiumbox.com` TTL; currently unproxied A to Hostinger) |
| VPS activation | 1–2 min |

**Hostinger write freeze: about 15–25 minutes** if Gate 3 is clean. Budget **30 minutes** of no new payments/cases. Cashfree will 503 and retry.

## Exact next action

**Do not freeze in this step.** Next authorized prompt should be: execute Hostinger freeze (Cron #1/#2 off + `artisan down`) and prove watermarks are stable — still no extract until that proof.

Do **not** run `db:sync-delta --apply`. Do **not** `deskd`. Do **not** change DNS until a frozen apply’s Gate 3 passes.
