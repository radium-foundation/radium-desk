# P-12-44 — Remaining failures register


**Matrix baseline:** P-12-32 @ `27b03d19`; **P-13-06** @ `b54b0a53`; **P-13-34** refresh @ `origin/main` **`7acde4aa470280dbab916074b29eb42e30db75bc`** (P-13-33 evidence).


## Totals (recomputed from 228 rows)


| Classification | Count |
| --- | ---: |
| VERIFIED FIXED | 210 |
| STILL FAILING | 18 |
| ENVIRONMENT BLOCKED | 0 |

> **Register note (P-13-34):** Rows still labeled **STILL FAILING** below were **not re-run** on `7acde4aa` during P-13-33. Their **current-main status is UNKNOWN / NOT REPRODUCED** — historical P-12-32 failure text is retained until a focused re-run.

## STILL FAILING by workstream


| Workstream | Count |
| --- | ---: |
| WS-04 | 1 |
| WS-10 | 17 |

## Full STILL FAILING list

- `Tests.Feature.AdministrationHomeTest::test_admin_can_access_administration_home` (WS-10) — P-12-32 phpunit @27b03d19 → FAIL **[UNKNOWN on current main — NOT REPRODUCED in P-13-33]**
- `Tests.Feature.AdministrationHomeTest::test_administration_workspace_tabs_link_to_existing_routes` (WS-10) — P-12-32 phpunit @27b03d19 → FAIL **[UNKNOWN on current main — NOT REPRODUCED in P-13-33]**
- `Tests.Feature.AgentDashboardOwnershipTest::test_agent_completed_classification_remains_available_without_navigation_tab` (WS-10) — P-12-32 phpunit @27b03d19 → FAIL **[UNKNOWN on current main — NOT REPRODUCED in P-13-33]**
- `Tests.Feature.AssignReferencePhase9PerformanceTest::test_batch_assign_coalesces_driver_guide_snapshot_automation_and_preserves_audits` (WS-10) — P-12-32 phpunit @27b03d19 → FAIL **[UNKNOWN on current main — NOT REPRODUCED in P-13-33]**
- `Tests.Feature.BonvoiceLiveCallAssistTest::test_existing_customer_creates_agent_alert_and_notification` (WS-10) — P-12-32 phpunit @27b03d19 → FAIL **[UNKNOWN on current main — NOT REPRODUCED in P-13-33]**
- `Tests.Feature.CashfreeHistoricalRecoveryCommandTest::test_failed_webhook_creates_missing_order_on_replay` (WS-10) — P-12-32 phpunit @27b03d19 → FAIL **[UNKNOWN on current main — NOT REPRODUCED in P-13-33]**
- `Tests.Feature.JulyLeaveReconcileCommandTest::test_reconcile_approves_pending_creates_missing_and_matches_matrix` (WS-10) — P-12-32 phpunit @27b03d19 → FAIL **[UNKNOWN on current main — NOT REPRODUCED in P-13-33]**
- `Tests.Feature.PresenceEngineTest::test_superadmin_login_does_not_create_workforce_session` (WS-10) — P-12-32 phpunit @27b03d19 → FAIL **[UNKNOWN on current main — NOT REPRODUCED in P-13-33]**
- `Tests.Feature.ProductOrderRequestSerialExclusionTest::test_rd_order_missing_serial_still_shows_request_serial_action` (WS-10) — P-12-32 phpunit @27b03d19 → FAIL **[UNKNOWN on current main — NOT REPRODUCED in P-13-33]**
- `Tests.Feature.Refunds.RefundExecutionReferenceGuardTest::test_wallet_completion_ignores_manual_execution_reference_fields` (WS-10) — P-12-32 phpunit @27b03d19 → ERROR **[UNKNOWN on current main — NOT REPRODUCED in P-13-33]**
- `Tests.Feature.Refunds.RefundStatutoryAdjustmentTest::test_generated_credit_note_follows_ca_monthly_negative_offset_treatment` (WS-10) — P-12-32 phpunit @27b03d19 → FAIL **[UNKNOWN on current main — NOT REPRODUCED in P-13-33]**
- `Tests.Feature.ReturnExchange.OnlineReturnRefundAllocationExecutionTest::test_wallet_line_executes_via_radiumbox_client_with_idempotency` (WS-04) — P-12-32 phpunit @27b03d19 → FAIL **[UNKNOWN on current main — NOT REPRODUCED in P-13-33]**
- `Tests.Feature.Workforce.LeaveOperationalImpactPhase2ATest::test_employee_with_workload_shows_warning_and_counts` (WS-10) — P-12-32 phpunit @27b03d19 → FAIL **[UNKNOWN on current main — NOT REPRODUCED in P-13-33]**
- `Tests.Feature.Workforce.WorkforceEventIsolationTest::test_reserved_event_types_exist_but_are_not_produced_yet` (WS-10) — P-12-32 phpunit @27b03d19 → FAIL **[UNKNOWN on current main — NOT REPRODUCED in P-13-33]**
- `Tests.Feature.WorkspaceRemarkActionTest::test_note_action_returns_dashboard_refresh_payload` (WS-10) — P-12-32 phpunit @27b03d19 → FAIL **[UNKNOWN on current main — NOT REPRODUCED in P-13-33]**
- `Tests.Unit.Notifications.DriverInstallationGuideEmailTemplateTest::test_template_renders_branded_production_content_with_primary_cta` (WS-10) — P-12-32 phpunit @27b03d19 → FAIL **[UNKNOWN on current main — NOT REPRODUCED in P-13-33]**
- `Tests.Unit.Notifications.NotificationAuthorityServiceTest::test_ira_telegram_bridge_respects_global_channel_toggle` (WS-10) — P-12-32 phpunit @27b03d19 → FAIL **[UNKNOWN on current main — NOT REPRODUCED in P-13-33]**
- `Tests.Unit.OrderCompletionTooltipTest::test_transaction_assign_tooltip_includes_service_reference_and_assignee` (WS-10) — P-12-32 phpunit @27b03d19 → ERROR **[UNKNOWN on current main — NOT REPRODUCED in P-13-33]**

