# BR-05 — Resilient Gmail Synchronization

## Problem

A transient Gmail HTTP 400 on a valid message aborted the entire mailbox sync. Because the history cursor was only committed after a fully successful run, production could become permanently stuck replaying the same backlog.

## Architecture

Sync path is unchanged:

```
Scheduler → inbound-email:sync-gmail → IncomingEmailGmailSyncService
  → GmailInboundEmailProvider::pullIncremental()
    → GmailApiClient history pages + message fetches
    → IncomingEmailIngestService::ingest()
    → incremental cursor commit per history entry
```

### Resilience rules

1. **Retry** HTTP 400 / 429 / 5xx with exponential backoff.
2. **Isolate** message fetch failures — log, record in `gmail_sync_message_failures`, skip, continue.
3. **Commit incrementally** after each history entry so a crash resumes without replaying completed work.
4. **Re-baseline only** on genuine history expiry (HTTP 404), never for transient 400s.

### Administration

Administration → API Health shows Gmail metrics and actions:

- Run Gmail Sync Now
- Re-baseline Cursor (confirmation required)
- View Gmail Sync Logs
- View Failed Messages

Also appears as an Integration Health pill and on Operations → Performance.

## Recovery

The next successful scheduled sync auto-recovers a stuck mailbox: failed messages are skipped after retries, successful ones ingest, and the cursor advances. Manual re-baseline is only for invalid/expired cursors.
