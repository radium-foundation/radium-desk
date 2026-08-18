# Apply stop — cashfree_webhook_logs FK to missing incident

Generation `tier1-rehearsal-20260815-085333-90fe9d`, skipExtract=true. Preflight passed. Stopped on first safety failure. No recovery.

## Failure

`cashfree_webhook_logs` id **46048** update set `incident_id=38964`. VPS has no incidents.id 38964 (max 38963). Chunk rolled back. Existing VPS row 46048 unchanged (`incident_id` null).

Cause: `tablesInSyncOrder()` sorts same `sync_order` **alphabetically**, so `cashfree_webhook_logs` (40) runs **before** `incidents` (40) despite `depends_on: [orders]`.

## Progress preserved

| Table | Checkpoint | Notes |
|-------|------------|--------|
| 14 early tables | yes, 18:46:39 | re-upsert |
| **orders** | **yes, seq 20, last_id 38530, 18:47:17** | VPS count **38318** = generation |
| cashfree_webhook_logs | seq **23**, last_id 46000, 18:47:32 | chunk 24 rolled back; count still 46048 |
| incidents | **no** | count still 38849 |

Inbox 108/108 unchanged. Locks free. Hostinger not written.
