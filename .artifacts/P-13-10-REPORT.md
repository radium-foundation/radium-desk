# P-13-10 Report — Interakt / WhatsApp timeline cluster

| Field | Value |
| --- | --- |
| Global Prompt ID | **P-13-10** |
| Ledger ID | **RadiumDesk-P-13-10** |
| Branch | `feature/p13-10-interakt-whatsapp` |
| Worktree | `.worktrees/p13-10-interakt-whatsapp` |
| Baseline | `700526c68056f0dde1864de60b3671257dae83f5` (`origin/main` post PR #209) |
| Program baseline | `f358ab04a9a57623085f19c47c6e0045d9b0a7ea` |
| Report started | 2026-11-13 09:29:00 IST |
| Report completed | 2026-11-13 09:32:01 IST |

## Assigned rows (6)

| CSV line | Test method | Status |
| --- | --- | --- |
| 87 | `InteraktWebhookTest::test_incoming_webhook_stores_message_and_timeline_event` | **VERIFIED FIXED** |
| 88 | `InteraktWebhookTest::test_official_api_failed_webhook_persists_failure_metadata` | **VERIFIED FIXED** |
| 89 | `InteraktWebhookTest::test_outgoing_template_send_and_status_webhooks_update_timeline` | **VERIFIED FIXED** |
| 185 | `WhatsAppConversationFeatureTest::test_drawer_renders_open_in_interakt_link_without_message_text` | **VERIFIED FIXED** |
| 186 | `WhatsAppConversationFeatureTest::test_webhook_messages_produce_single_runtime_timeline_card` | **VERIFIED FIXED** |
| 187 | `WhatsAppTemplateDispatcherTest::test_timeline_shows_whatsapp_template_sent_event_without_message_body` | **VERIFIED FIXED** |

Shard source: `.artifacts/P-13-07-REPORT.md` § P-13-10.

## Root cause and evidence

| Row(s) | Classification | Evidence label | Summary |
| --- | --- | --- | --- |
| 87, 88, 89, 186, 187 | Application defect | **VERIFIED** | `Customer360OperatorTimelinePresentation` hid all `TimelineEventType::WhatsApp` summary cards (`whatsapp:summary:*`) and `WhatsAppTemplateSent` as “duplicate communication,” so `Customer360TimelineService::forOrder()` returned zero WhatsApp events despite persisted `interakt_messages` / dispatches. |
| 185 | Application defect | **VERIFIED** | Business timeline enabled by default; WhatsApp summaries never reached the tab payload for the same visibility rules; milestone view lacked `unified-timeline-summary-fields` / Interakt action affordances. |

**Harness note (environment):** Shared `vendor` autoload initially pointed at **p13-09** worktree (`composer dump-autoload -o` from **p13-10** required before reproduction matched code under test). Not a product defect.

## Integration contract and idempotency

| Topic | Finding | Label |
| --- | --- | --- |
| Webhook ingest | Messages still written via outbox drain in tests (`postInteraktWebhookAndDrain`); duplicate webhook test unchanged | **VERIFIED** (existing) |
| Timeline dedupe | Canonical card remains `dedupeKey` `whatsapp:summary:{phone}` | **VERIFIED** preserved |
| Template dispatch | `WhatsAppTemplateDispatchTimelineSource` events remain distinct from conversation summary | **VERIFIED** preserved |
| Idempotency | No change to webhook dedupe / message_id uniqueness | **VERIFIED** |
| Live messaging / production | No outbound sends; tests use `Http::fake` and local fixtures only | **VERIFIED** |

## Behaviour changes

| Area | Preserved | Extended | Changed | Removed |
| --- | --- | --- | --- | --- |
| Operator timeline | Per-message WhatsApp noise still suppressed | `whatsapp:summary:*` and `WhatsAppTemplateSent` visible again | Business milestone unified layout for WhatsApp summary | Rule that hid summary-field WhatsApp cards |
| Business timeline tab | Milestone clustering | Inline summary fields + Interakt link on unified WhatsApp milestones | `unified-timeline-summary-fields` class on milestone DL | — |
| Raw timeline activity | Nested raw event expander | WhatsApp summary fields inline on activity items | — | — |

## Tests

**Cluster (6 methods):**

```bash
cd .worktrees/p13-10-interakt-whatsapp
FILTER='test_incoming_webhook_stores_message_and_timeline_event|test_official_api_failed_webhook_persists_failure_metadata|test_outgoing_template_send_and_status_webhooks_update_timeline|test_drawer_renders_open_in_interakt_link_without_message_text|test_webhook_messages_produce_single_runtime_timeline_card|test_timeline_shows_whatsapp_template_sent_event_without_message_body'
DESK_PHP_BIN=/opt/homebrew/bin/php ./bin/phpunit-isolated --filter "$FILTER" \
  tests/Feature/InteraktWebhookTest.php \
  tests/Feature/WhatsAppConversationFeatureTest.php \
  tests/Feature/WhatsAppTemplateDispatcherTest.php
```

| Run | Result | Duration |
| --- | --- | --- |
| Cluster | **6/6 PASS** | ~6.8s |
| Regressions (`InteraktWebhookTest`, `WhatsAppConversationFeatureTest`, `WhatsAppTemplateDispatcherTest`, `Customer360BusinessTimelineTest`, `WhatsAppConversationAggregatorTest`) | **33/33 PASS** | ~30.3s |

Full PHPUnit suite: **NOT RUN**.

## Git / CI

| Item | Status |
| --- | --- |
| Commit | Pending |
| PR | Pending |
| CI | Pending |
| Merge SHA | Pending |

## Risks and approvals

- **Residual:** Legacy per-message WhatsApp timeline rows (non-`whatsapp:summary:`) remain hidden to avoid duplicate cards — intentional.
- **Owner approval:** None required for this scope.

## Live messaging / production

**NO — Not performed** for production integration changes, live messaging, and deployment.

## Wallet preservation

`feature/p18-33-unified-wallet-display` @ **`d94816153cd2c89f8175d29a1510b38b4c16df6b`** — **VERIFIED** unchanged.

## Stage durations

| Stage | Duration |
| --- | --- |
| Worktree + prechecks | ~3 min |
| Repro + root cause | ~5 min |
| Fix + cluster validation | ~8 min |
| Regressions + git gates | ~5 min |
