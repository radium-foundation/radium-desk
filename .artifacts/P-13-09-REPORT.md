# P-13-09 Report — Customer 360 drawer WS-10 cluster

| Field | Value |
| --- | --- |
| Global Prompt ID | **P-13-09** |
| Ledger ID | **RadiumDesk-P-13-09** |
| Branch | `feature/p13-09-customer-360` |
| Worktree | `.worktrees/p13-09-customer-360` |
| Baseline | `a99eda54bd4bb3af3c93bfcae6352f5293344953` (`origin/main` post PR #208) |
| Known baseline (program) | `f358ab04a9a57623085f19c47c6e0045d9b0a7ea` |
| Report started | 2026-11-13 09:25:17 IST |
| Agent | Cursor Composer |

## Assigned rows (9)

| CSV line | Test method | Status |
| --- | --- | --- |
| 16 | `BonvoiceWebhookTest::test_customer_360_health_card_shows_latest_call_status_and_relative_time` | **VERIFIED FIXED** |
| 17 | `BonvoiceWebhookTest::test_customer_360_timeline_tab_renders_ivr_call_event` | **VERIFIED FIXED** |
| 21 | `Customer360RadiumBoxSyncTest::test_customer_360_shows_last_synced_freshness_when_serial_exists` | **VERIFIED FIXED** |
| 22 | `Customer360RadiumBoxSyncTest::test_customer_360_shows_synchronization_history` | **VERIFIED FIXED** (app + harness) |
| 23 | `Customer360RadiumBoxSyncTest::test_manual_radiumbox_sync_enriches_order_and_returns_refreshed_drawer` | **VERIFIED FIXED** |
| 82 | `InquiryLifecyclePhase1Test::test_customer_360_shows_case_and_enquiry_type_for_inquiry` | **VERIFIED FIXED** |
| 162 | `ServiceCaseStatusSupportAppointmentSyncTest::test_closed_incident_does_not_expose_active_scheduled_support_card` | **VERIFIED FIXED** |
| 163 | `ServiceCaseStatusSupportAppointmentSyncTest::test_closed_incident_hides_scheduled_support_card_after_status_sync` | **VERIFIED FIXED** (no statutory exception on re-run) |
| 175 | `SupportAppointmentBookingTest::test_customer_360_hides_support_appointments_section_when_none_exist` | **VERIFIED FIXED** |

Shard source: `.artifacts/P-13-07-REPORT.md` § P-13-09.

## Root cause and evidence

| Row(s) | Classification | Evidence label | Summary |
| --- | --- | --- | --- |
| 16 | Application defect | **VERIFIED** | Customer snapshot migration dropped **Last Call** rendering; `healthCard['last_call']` still populated in `Customer360Service`. |
| 17 | Application defect | **VERIFIED** | Business timeline milestones for inbound IVR used `CustomerContact` icon (`bi-person`); tests expect `bi-telephone`. |
| 21, 23 | Application defect | **VERIFIED** | Synced serial state used chip UI without `bi-check-circle` in markup. |
| 22 | Harness + environment | **VERIFIED** | `QUEUE_CONNECTION=sync` ran `RadiumBoxOrderEnrichmentJob` on drawer GET without `Http::fake`/`Queue::fake`, surfacing `RadiumBoxEnrichmentRetryException` as HTTP 500. Title case **Synchronization History** also drifted. |
| 82 | Application defect | **VERIFIED** | Inquiry **Type** row missing on device card; internal `INQ-*` order id leaked via `data-c360-search-index`. |
| 162, 163, 175 | Application defect | **VERIFIED** | Support appointments partial always rendered (empty state); tests expect section omitted when no scheduled appointments or closed incident. |
| 163 (statutory) | Not reproduced | **VERIFIED** | P-13-07 flagged `ServiceStatutoryInvoicePermanentFailureException`; focused re-run after fixes passed with no statutory overlap. |

## Behaviour changes

| Area | Preserved | Extended | Changed | Removed |
| --- | --- | --- | --- | --- |
| Health card | WhatsApp/Email snapshot rows | Last Call row with status + relative time | — | — |
| Timeline | Business milestone clustering | IVR milestones use `bi-telephone` | — | — |
| Device | Sync chip semantics | `bi-check-circle` when serial present; inquiry **Type** row | Sync history heading title case | — |
| Support appointments | Data filtering for closed cases (service layer) | — | Overview section hidden when collection empty | Empty-state shell when no appointments |
| Inquiry search palette | SC reference search | — | `orderId` omitted from search index for inquiry orders | — |
| RadiumBox sync test | Other sync tests unchanged | — | `Queue::fake()` in sync-history test | — |

## Focused tests

**Cluster (9 methods, 5 files):**

```bash
cd .worktrees/p13-09-customer-360
FILTER='test_customer_360_health_card_shows_latest_call_status_and_relative_time|test_customer_360_timeline_tab_renders_ivr_call_event|test_customer_360_shows_last_synced_freshness_when_serial_exists|test_customer_360_shows_synchronization_history|test_manual_radiumbox_sync_enriches_order_and_returns_refreshed_drawer|test_customer_360_shows_case_and_enquiry_type_for_inquiry|test_closed_incident_does_not_expose_active_scheduled_support_card|test_closed_incident_hides_scheduled_support_card_after_status_sync|test_customer_360_hides_support_appointments_section_when_none_exist'
DESK_PHP_BIN=/opt/homebrew/bin/php ./bin/phpunit-isolated --filter "$FILTER" \
  tests/Feature/BonvoiceWebhookTest.php \
  tests/Feature/Customer360RadiumBoxSyncTest.php \
  tests/Feature/InquiryLifecyclePhase1Test.php \
  tests/Feature/ServiceCaseStatusSupportAppointmentSyncTest.php \
  tests/Feature/SupportAppointmentBookingTest.php
```

| Run | Result | Duration |
| --- | --- | --- |
| Final cluster | **9/9 PASS** | ~11.3s |

**Additional regressions (partial):** `Customer360RadiumBoxSyncTest` full file — **19/22 PASS**; failures are **Vite manifest** worktree env (booking signed URL), not introduced by this diff. `Customer360DrawerTest` — 2 failures (**pre-existing** order-id copy assertion vs `order-identifier` link; Vite on dashboard host).

Full PHPUnit suite: **NOT RUN**.

## Git / CI

| Item | Status |
| --- | --- |
| Commit | Pending push |
| PR | Pending |
| CI | Pending |
| Merge SHA | Pending |

## Security / privacy

- Last Call and inquiry metadata use existing authorized `Customer360Service` data; no new PII surfaces.
- Inquiry internal order ids no longer embedded in client-side search index JSON (reduces accidental exposure in DOM).
- Authorization paths unchanged.

**Residual risks:** Operators without scheduled appointments no longer see the support-appointments empty state on Overview (section omitted). Overflow “Schedule Appointment” still available.

## Wallet preservation

`feature/p18-33-unified-wallet-display` @ **`d94816153cd2c89f8175d29a1510b38b4c16df6b`** — **VERIFIED** unchanged.

## Deployment / production

**NO — Not performed** for deployment and production changes.

## Stage durations (measured)

| Stage | Duration |
| --- | --- |
| Investigation + repro (handoff) | ~15 min (prior segment) |
| Implementation + cluster validation | ~25 min |
| Report + git gates | in progress |
