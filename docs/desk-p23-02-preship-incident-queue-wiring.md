# P-23-02 — Pre-shipment incident retry queue wiring

## Worker evidence (repository only)

| Source | Queue drain order |
|--------|-------------------|
| `config/queue_routing.php` | `critical` → `notifications` → `default` → `maintenance` |
| `App\Infrastructure\Queue\QueueRouting::workerOrder()` | Same four names (env-overridable via `QUEUE_NAME_*`) |
| `App\Infrastructure\Queue\QueueRouting::scheduledWorkerCommand()` | `queue:work database --queue=critical,notifications,default,maintenance ...` |
| `tests/Feature/QueueRoutingTest.php` | Asserts scheduled worker command includes the four-queue list |
| `tools/config.sh` | `SUPERVISOR_PROGRAM="radium-desk-queue-worker"` (program name only; **no** queue list in repo) |

**Production Supervisor `command=` line: NOT VERIFIED** in this repository (no checked-in supervisor unit file for KVM).

## P-23-02 decision

`ClosePreShipmentCancellationIncidentJob` (identity **`preship-cancel-close-incident`**) is dispatched to the **`critical`** queue via `QueueRouting::critical()`, which **is** included in the standard worker drain list above.

The earlier dedicated queue name `preship-cancel-close-incident` would **not** be consumed by the default worker and must **not** be used without a Supervisor change.

## Before enabling `PRE_SHIPMENT_CANCELLATION_ENABLED=true`

1. Confirm `radium-desk-queue-worker` (or Hostinger Cron #2 scheduler drain) processes **`critical`** — same gate as other critical jobs (catalog sync, etc.).
2. After deploy + flag enable: restart **`radium-desk-queue-worker`** so workers pick up new code.
3. Do **not** assume incident retries run until a held-service-case job is observed completing in logs.

No production Supervisor edits are performed in P-23-02.