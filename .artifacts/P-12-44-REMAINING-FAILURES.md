# P-12-44 — Remaining failures register


**Matrix baseline:** P-12-32 @ `27b03d19` reconciled @ `origin/main` **`b54b0a53`** (2026-11-13 IST, post **P-13-06** WS-10 canonical register reconciliation).


## Totals (recomputed from 228 rows)


| Classification | Count |
| --- | ---: |
| VERIFIED FIXED | 194 |
| STILL FAILING | 34 |
| ENVIRONMENT BLOCKED | 0 |

## STILL FAILING by workstream


| Workstream | Count |
| --- | ---: |
| WS-04 | 1 |
| WS-10 | 31 |
| WS-11 | 2 |

## Full STILL FAILING list

- `Tests.Feature.AdministrationHomeTest::test_admin_can_access_administration_home` (WS-10) — P-12-32 phpunit @27b03d19 → FAIL
- `Tests.Feature.AdministrationHomeTest::test_administration_workspace_tabs_link_to_existing_routes` (WS-10) — P-12-32 phpunit @27b03d19 → FAIL
- `Tests.Feature.AgentDashboardOwnershipTest::test_agent_completed_classification_remains_available_without_navigation_tab` (WS-10) — P-12-32 phpunit @27b03d19 → FAIL
- `Tests.Feature.AssignReferencePhase9PerformanceTest::test_batch_assign_coalesces_driver_guide_snapshot_automation_and_preserves_audits` (WS-10) — P-12-32 phpunit @27b03d19 → FAIL
- `Tests.Feature.Automation.CustomerWaitingAppointmentRepairCommandTest::test_closed_incident_is_skipped` (WS-10) — P-12-32 phpunit @27b03d19 → FAIL
- `Tests.Feature.Automation.CustomerWaitingLegacyCleanupTest::test_cleanup_writes_timeline_entry_without_customer_notifications` (WS-10) — P-12-32 phpunit @27b03d19 → FAIL
- `Tests.Feature.BonvoiceLiveCallAssistTest::test_existing_customer_creates_agent_alert_and_notification` (WS-10) — P-12-32 phpunit @27b03d19 → FAIL
- `Tests.Feature.CashfreeHistoricalRecoveryCommandTest::test_failed_webhook_creates_missing_order_on_replay` (WS-10) — P-12-32 phpunit @27b03d19 → FAIL
- `Tests.Feature.IncomingEmailIntakePhase1Test::test_ingest_propagates_unrelated_query_exceptions` (WS-10) — P-12-32 phpunit @27b03d19 → FAIL
- `Tests.Feature.IncomingEmailIntakePhase1Test::test_ingest_returns_existing_message_on_rfc_message_id_insert_race` (WS-10) — P-12-32 phpunit @27b03d19 → ERROR
- `Tests.Feature.Inventory.InventoryPosOperationalWorkflowTest::test_operator_workflow_catalog_serial_lifecycle_sale_invoice_and_finance` (WS-10) — P-12-32 phpunit @27b03d19 → FAIL
- `Tests.Feature.JulyLeaveReconcileCommandTest::test_reconcile_approves_pending_creates_missing_and_matches_matrix` (WS-10) — P-12-32 phpunit @27b03d19 → FAIL
- `Tests.Feature.OperationsSupportOwnershipTest::test_invalid_serial_case_assigns_support_agent` (WS-10) — P-12-32 phpunit @27b03d19 → FAIL
- `Tests.Feature.OperationsSupportOwnershipTest::test_offline_agent_skipped_for_invalid_serial_reassignment` (WS-10) — P-12-32 phpunit @27b03d19 → FAIL
- `Tests.Feature.OrderIdentityProtectionTest::serial_correction_remains_locked_after_correction` (WS-10) — P-12-32 phpunit @27b03d19 → FAIL
- `Tests.Feature.OrderIdentityProtectionTest::timeline_includes_initial_protection_event_only_once_per_field` (WS-10) — P-12-32 phpunit @27b03d19 → FAIL
- `Tests.Feature.PresenceEngineTest::test_superadmin_login_does_not_create_workforce_session` (WS-10) — P-12-32 phpunit @27b03d19 → FAIL
- `Tests.Feature.ProductOrderRequestSerialExclusionTest::test_rd_order_missing_serial_still_shows_request_serial_action` (WS-10) — P-12-32 phpunit @27b03d19 → FAIL
- `Tests.Feature.Refunds.RefundExecutionReferenceGuardTest::test_wallet_completion_ignores_manual_execution_reference_fields` (WS-10) — P-12-32 phpunit @27b03d19 → ERROR
- `Tests.Feature.Refunds.RefundStatutoryAdjustmentTest::test_generated_credit_note_follows_ca_monthly_negative_offset_treatment` (WS-10) — P-12-32 phpunit @27b03d19 → FAIL
- `Tests.Feature.RequestCorrectSerialTest::test_manual_request_correct_serial_persists_audit_and_timeline_entries` (WS-10) — P-12-32 phpunit @27b03d19 → FAIL
- `Tests.Feature.ReturnExchange.OnlineReturnRefundAllocationExecutionTest::test_wallet_line_executes_via_radiumbox_client_with_idempotency` (WS-04) — P-12-32 phpunit @27b03d19 → FAIL
- `Tests.Feature.WaitingStateOwnershipTest::test_no_op_reassignment_does_not_create_audit_or_notification` (WS-10) — P-12-32 phpunit @27b03d19 → FAIL
- `Tests.Feature.Workforce.LeaveOperationalImpactPhase2ATest::test_employee_with_workload_shows_warning_and_counts` (WS-10) — P-12-32 phpunit @27b03d19 → FAIL
- `Tests.Feature.Workforce.WorkforceEventIsolationTest::test_reserved_event_types_exist_but_are_not_produced_yet` (WS-10) — P-12-32 phpunit @27b03d19 → FAIL
- `Tests.Feature.WorkspaceRemarkActionTest::test_note_action_returns_dashboard_refresh_payload` (WS-10) — P-12-32 phpunit @27b03d19 → FAIL
- `Tests.Unit.ChannelIngest.ChannelIngestPayloadHasherTest::test_legacy_rdp_service_hash_matches_pre_v4_0_95_stored_hash_on_replay` (WS-10) — P-12-32 phpunit @27b03d19 → FAIL
- `Tests.Unit.Notifications.DriverInstallationGuideEmailTemplateTest::test_template_renders_branded_production_content_with_primary_cta` (WS-10) — P-12-32 phpunit @27b03d19 → FAIL
- `Tests.Unit.Notifications.NotificationAuthorityServiceTest::test_ira_telegram_bridge_respects_global_channel_toggle` (WS-10) — P-12-32 phpunit @27b03d19 → FAIL
- `Tests.Unit.Operations.TeamAvailabilityOverviewOptimizationTest::test_operational_roster_includes_all_tracked_members_with_batched_queries` (WS-10) — P-12-32 phpunit @27b03d19 → FAIL
- `Tests.Unit.Operations.TeamAvailabilityOverviewOptimizationTest::test_overview_reuses_single_team_member_query_and_batched_sessions` (WS-10) — P-12-32 phpunit @27b03d19 → FAIL
- `Tests.Unit.OrderCompletionTooltipTest::test_transaction_assign_tooltip_includes_service_reference_and_assignee` (WS-10) — P-12-32 phpunit @27b03d19 → ERROR
- `Tests.Unit.StatutoryInvoice.EInvoiceCatalogUqcReadinessTest::test_p186_did_not_infer_pcs_or_nos_without_owner_decision` (WS-11) — P-12-32 phpunit @27b03d19 → ERROR
- `Tests.Unit.StatutoryInvoice.EInvoiceCatalogUqcReadinessTest::test_p187_owner_decision_assigns_pcs_only_to_the_nine_hardware_skus` (WS-11) — P-12-32 phpunit @27b03d19 → ERROR
