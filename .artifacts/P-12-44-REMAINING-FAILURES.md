# P-12-44 — Remaining failures register


**Matrix baseline:** P-12-32 @ `27b03d19`; **P-13-06** @ `b54b0a53`; **P-13-36** @ `bbf27961`; **P-13-38** @ `e34ac3cf`; **P-13-40** refresh @ `origin/main` **`65493e8b0548bb5146240edb2d245649a338a186`** (P-13-39 row 188).


## Totals (recomputed from 228 rows)


| Classification | Count |
| --- | ---: |
| VERIFIED FIXED | 216 |
| STILL FAILING | 12 |
| ENVIRONMENT BLOCKED | 0 |

> **P-13-35:** Remaining rows were **VERIFIED FAIL** on `bbf27961` except rows **223** (P-13-37) and **188** (P-13-39).


## STILL FAILING by workstream


| Workstream | Count |
| --- | ---: |
| WS-10 | 12 |

## Full STILL FAILING list

- `Tests.Feature.AssignReferencePhase9PerformanceTest::test_batch_assign_coalesces_driver_guide_snapshot_automation_and_preserves_audits` (WS-10) — P-12-32 phpunit @27b03d19 → FAIL
- `Tests.Feature.BonvoiceLiveCallAssistTest::test_existing_customer_creates_agent_alert_and_notification` (WS-10) — P-12-32 phpunit @27b03d19 → FAIL
- `Tests.Feature.CashfreeHistoricalRecoveryCommandTest::test_failed_webhook_creates_missing_order_on_replay` (WS-10) — P-12-32 phpunit @27b03d19 → FAIL
- `Tests.Feature.JulyLeaveReconcileCommandTest::test_reconcile_approves_pending_creates_missing_and_matches_matrix` (WS-10) — P-12-32 phpunit @27b03d19 → FAIL
- `Tests.Feature.PresenceEngineTest::test_superadmin_login_does_not_create_workforce_session` (WS-10) — P-12-32 phpunit @27b03d19 → FAIL
- `Tests.Feature.ProductOrderRequestSerialExclusionTest::test_rd_order_missing_serial_still_shows_request_serial_action` (WS-10) — P-12-32 phpunit @27b03d19 → FAIL
- `Tests.Feature.Refunds.RefundExecutionReferenceGuardTest::test_wallet_completion_ignores_manual_execution_reference_fields` (WS-10) — P-12-32 phpunit @27b03d19 → ERROR
- `Tests.Feature.Refunds.RefundStatutoryAdjustmentTest::test_generated_credit_note_follows_ca_monthly_negative_offset_treatment` (WS-10) — P-12-32 phpunit @27b03d19 → FAIL
- `Tests.Feature.Workforce.WorkforceEventIsolationTest::test_reserved_event_types_exist_but_are_not_produced_yet` (WS-10) — P-12-32 phpunit @27b03d19 → FAIL
- `Tests.Feature.WorkspaceRemarkActionTest::test_note_action_returns_dashboard_refresh_payload` (WS-10) — P-12-32 phpunit @27b03d19 → FAIL
- `Tests.Unit.Notifications.DriverInstallationGuideEmailTemplateTest::test_template_renders_branded_production_content_with_primary_cta` (WS-10) — P-12-32 phpunit @27b03d19 → FAIL
- `Tests.Unit.Notifications.NotificationAuthorityServiceTest::test_ira_telegram_bridge_respects_global_channel_toggle` (WS-10) — P-12-32 phpunit @27b03d19 → FAIL

