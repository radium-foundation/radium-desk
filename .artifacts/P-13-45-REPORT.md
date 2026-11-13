# P-13-45 — Row 15 Bonvoice live call + RadiumBox enrichment

| Global Prompt | **P-13-45** |
| Cursor ledger | **RadiumDesk-P-13-45** |
| Baseline | **`70b3843`** @ `origin/main` (post P-13-43 merge) |
| Timestamp | **2026-11-13 ~14:50 IST** |

---

## Root cause

**Test harness (TEST_SETUP)** — not missing application dispatch.

| Item | Evidence |
| --- | --- |
| Failure | `Queue::assertPushed(RadiumBoxOrderEnrichmentJob::class)` failed after alert + notification assertions passed |
| App path | `BonvoiceLiveCallAssistService::maybeTriggerRadiumBoxSync` → `RadiumBoxAutoSyncTriggerService::maybeDispatch(..., BonvoiceLiveCallMatch)` → `RadiumBoxOrderEnrichmentService::dispatch` |
| Gate | `shouldDispatch()` returns **false** when `config('radiumbox.enabled')` is **false** (default in `config/radiumbox.php` / test env) |
| Contrast | `RadiumBoxAutoSyncTriggerTest` opts in with `radiumbox.enabled` + `auto_sync.enabled` in `setUp`; Bonvoice test did not |

**Business contract:** When RadiumBox integration and auto-sync are enabled, a known-customer inbound live-call match **should** queue enrichment for orders that `needsEnrichment()`. Production default **off** is intentional; the test must opt in like other RadiumBox feature tests.

**External side effects:** `Notification::fake()` and `Queue::fake()` — no real calls, Telegram, email, or HTTP.

---

## Change

- `BonvoiceLiveCallAssistTest::test_existing_customer_creates_agent_alert_and_notification` — enable `radiumbox.enabled` + `auto_sync` for the scenario under test only.

No application / webhook / notification authority changes.

---

## Flow (verified)

```
POST /api/webhooks/bonvoice (Ringing, known phone)
  → BonvoiceLiveCallAssistService::maybeNotify
  → BonvoiceInboundCustomerResolver (CustomerFound + order/incident ids)
  → BonvoiceCallAlert persisted
  → RadiumBoxAutoSyncTriggerService::maybeDispatch (BonvoiceLiveCallMatch)
  → RadiumBoxOrderEnrichmentJob queued
  → IncomingCallAssistNotification (faked)
```

---

## PHPUnit (`bin/phpunit-isolated`, `DESK_PHP_BIN=/opt/homebrew/bin/php`)

| Scope | Result | Assertions | ms |
| --- | --- | ---: | ---: |
| Row 15 filter | **1/1 PASS** | 5 | ~2600 |
| `BonvoiceLiveCallAssistTest` | **20/20 PASS** | 79 | 18929 |
| `RadiumBoxAutoSyncTriggerTest` | **PASS** | — | — |
| `BonvoiceWebhookTest` | **PASS** | — | — |
| `BonvoiceIncomingCallLatencyTest` | **PASS** | — | — |

Full suite: **NOT RUN**.

---

## Preserved / Extended / Changed / Removed

| | |
| --- | --- |
| **Preserved** | Customer matching, alert ownership, notification copy, idempotency, fail-closed when RadiumBox disabled |
| **Extended** | — |
| **Changed** | Test config opt-in only |
| **Removed** | — |

---

## Production / wallet / externals

- Production access/deployment: **NO**
- External calls/notifications: **NO** (fakes)
- Wallet WIP @ **`d9481615`** — **unchanged**

---

## Risks

- Matrix row **15** CSV still **STILL FAILING** until register-reconcile.
- If `radiumbox.enabled` default or Bonvoice sync contract changes, this test must stay aligned with `RadiumBoxAutoSyncTriggerService` gates.

---

*End of P-13-45 report.*
