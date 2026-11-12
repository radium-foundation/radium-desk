# P-12-44 — Remaining failures register

**Matrix baseline:** P-12-32 @ `27b03d19` reconciled @ `origin/main` **`d709e856`** (2026-11-12 IST).

## Totals (recomputed from 228 rows)

| Classification | Count |
| --- | ---: |
| VERIFIED FIXED | 109 |
| STILL FAILING | 118 |
| ENVIRONMENT BLOCKED | 1 |

## STILL FAILING by workstream

| Workstream | Count |
| --- | ---: |
| WS-10 | 113 |
| WS-11 | 4 |
| WS-04 | 1 |

## Statutory PDF / Imagick (post P-12-43)

- `Tests.Feature.Customer360.Customer360StatutoryInvoiceActionsTest::test_customer_360_shows_failed_when_b2b_statutory_data_is_incomplete` — P-12-32 phpunit @27b03d19 → FAIL
- `Tests.Feature.StatutoryInvoice.EInvoiceIssuancePathTest::test_commerce_b2b_service_is_blocked_in_phase_a_and_queued_in_phase_b` — P-12-32 phpunit @27b03d19 → FAIL
- `Tests.Feature.StatutoryInvoice.IrnInputSnapshotTest::test_commerce_ingest_preserves_valid_uqc_and_rejects_invalid` — P-12-32 phpunit @27b03d19 → FAIL
- `Tests.Feature.StatutoryInvoice.IrnInputSnapshotTest::test_commerce_uqc_and_structured_billing_are_snapshotted_at_mint` — P-12-32 phpunit @27b03d19 → FAIL
- `Tests.Feature.StatutoryInvoice.RdServiceNetPhase1CleanTest::test_invalid_gstin_and_missing_statutory_data_fail_closed` — P-12-32 phpunit @27b03d19 → FAIL
- `Tests.Feature.StatutoryInvoice.ServiceGstSplitIssuanceTest::test_b2b_split_follows_seller_state_and_place_of_supply_not_billing_state` — P-12-32 phpunit @27b03d19 → FAIL
- `Tests.Feature.StatutoryInvoice.ServiceStatutoryInvoiceReconciliationBacklogTest::test_rdservice_net_ra_prefix_orders_are_reconciled` — P-12-32 phpunit @27b03d19 → FAIL
- `Tests.Feature.StatutoryInvoice.StatutoryInvoicePdfRegressionContractTest::test_contract_non_fitting_small_serial_set_is_all_or_nothing_to_annexure` — P-12-44 phpunit-isolated/php -n @ d709e856; ERROR Embedded invoice image missing: Logo (imagick OK via phpunit-isolated)
- `Tests.Feature.StatutoryInvoice.StatutoryInvoicePdfRegressionContractTest::test_contract_small_serial_set_renders_inline_only_when_measured_block_fits` — P-12-44 phpunit-isolated/php -n @ d709e856; ERROR Embedded invoice image missing: Logo (imagick OK via phpunit-isolated)
- `Tests.Unit.StatutoryInvoice.EInvoiceCatalogUqcReadinessTest::test_p186_did_not_infer_pcs_or_nos_without_owner_decision` — P-12-32 phpunit @27b03d19 → ERROR
- `Tests.Unit.StatutoryInvoice.EInvoiceCatalogUqcReadinessTest::test_p187_owner_decision_assigns_pcs_only_to_the_nine_hardware_skus` — P-12-32 phpunit @27b03d19 → ERROR
- `Tests.Unit.StatutoryInvoice.StatutoryInvoicePdfSerialCapacityTest::test_multipage_invoice_keeps_six_serials_on_page_one_without_annexure` — P-12-44 phpunit-isolated/php -n @ d709e856; ERROR Embedded invoice image missing: Logo (imagick OK via phpunit-isolated)
- `Tests.Unit.StatutoryInvoice.StatutoryInvoicePdfSerialCapacityTest::test_ten_or_fewer_serials_use_main_page_only` — P-12-44 phpunit-isolated/php -n @ d709e856; ERROR Embedded invoice image missing: Logo (imagick OK via phpunit-isolated)

## Full STILL FAILING list

- `Tests.Feature.AdministrationHomeTest::test_admin_can_access_administration_home` (WS-10)
- `Tests.Feature.AdministrationHomeTest::test_administration_workspace_tabs_link_to_existing_routes` (WS-10)
- `Tests.Feature.AgentDashboardOwnershipTest::test_agent_completed_classification_remains_available_without_navigation_tab` (WS-10)
- `Tests.Feature.AssignReferencePhase9PerformanceTest::test_batch_assign_coalesces_driver_guide_snapshot_automation_and_preserves_audits` (WS-10)
- `Tests.Feature.Automation.CustomerWaitingAppointmentRepairCommandTest::test_closed_incident_is_skipped` (WS-10)
- `Tests.Feature.Automation.CustomerWaitingLegacyCleanupTest::test_cleanup_writes_timeline_entry_without_customer_notifications` (WS-10)
- `Tests.Feature.AutomationOperationsDashboardTest::test_sidebar_shows_operations_entry_and_hides_automation_children_for_admin` (WS-10)
- `Tests.Feature.BonvoiceLiveCallAssistTest::test_existing_customer_creates_agent_alert_and_notification` (WS-10)
- `Tests.Feature.BonvoiceWebhookTest::test_customer_360_health_card_shows_latest_call_status_and_relative_time` (WS-10)
- `Tests.Feature.BonvoiceWebhookTest::test_customer_360_timeline_tab_renders_ivr_call_event` (WS-10)
- `Tests.Feature.CashBook.CashBookPhase1Test::test_cash_book_appears_in_sidebar_for_employee` (WS-10)
- `Tests.Feature.CashfreeHistoricalRecoveryCommandTest::test_failed_webhook_creates_missing_order_on_replay` (WS-10)
- `Tests.Feature.Customer360.Customer360StatutoryInvoiceActionsTest::test_customer_360_shows_failed_when_b2b_statutory_data_is_incomplete` (WS-10)
- `Tests.Feature.Customer360RadiumBoxSyncTest::test_customer_360_shows_last_synced_freshness_when_serial_exists` (WS-10)
- `Tests.Feature.Customer360RadiumBoxSyncTest::test_customer_360_shows_synchronization_history` (WS-10)
- `Tests.Feature.Customer360RadiumBoxSyncTest::test_manual_radiumbox_sync_enriches_order_and_returns_refreshed_drawer` (WS-10)
- `Tests.Feature.Dashboard.DashboardClassificationIndexTest::test_lean_load_skips_row_only_relations` (WS-10)
- `Tests.Feature.DashboardServiceCasesTest::test_admin_dashboard_shows_bulk_selection_and_inline_transaction_controls` (WS-10)
- `Tests.Feature.DashboardServiceCasesTest::test_completed_service_cases_always_show_within_sla` (WS-10)
- `Tests.Feature.DashboardServiceCasesTest::test_dashboard_defaults_to_ready_queue_filter_aligned_with_queue` (WS-10)
- `Tests.Feature.DashboardServiceCasesTest::test_dashboard_needs_attention_filter_shows_only_active_cases_with_missing_serial` (WS-10)
- `Tests.Feature.DashboardServiceCasesTest::test_dashboard_shows_service_case_grid_columns` (WS-10)
- `Tests.Feature.DashboardServiceCasesTest::test_dashboard_shows_sla_warning_and_overdue_for_pending_cases` (WS-10)
- `Tests.Feature.HybridRealtimePhase3Test::test_system_settings_page_shows_phase_three_hybrid_features` (WS-10)
- `Tests.Feature.IncomingEmailIntakePhase1Test::test_ingest_propagates_unrelated_query_exceptions` (WS-10)
- `Tests.Feature.IncomingEmailIntakePhase1Test::test_ingest_returns_existing_message_on_rfc_message_id_insert_race` (WS-10)
- `Tests.Feature.InquiryLifecyclePhase1Test::test_customer_360_shows_case_and_enquiry_type_for_inquiry` (WS-10)
- `Tests.Feature.InteraktWebhookTest::test_incoming_webhook_stores_message_and_timeline_event` (WS-10)
- `Tests.Feature.InteraktWebhookTest::test_official_api_failed_webhook_persists_failure_metadata` (WS-10)
- `Tests.Feature.InteraktWebhookTest::test_outgoing_template_send_and_status_webhooks_update_timeline` (WS-10)
- `Tests.Feature.Inventory.InventoryPosOperationalWorkflowTest::test_operator_workflow_catalog_serial_lifecycle_sale_invoice_and_finance` (WS-10)
- `Tests.Feature.IraOperationsBrainTest::test_admin_dashboard_shows_ira_briefing_panel` (WS-10)
- `Tests.Feature.JulyLeaveReconcileCommandTest::test_reconcile_approves_pending_creates_missing_and_matches_matrix` (WS-10)
- `Tests.Feature.MonthlyAttendanceMatrixTest::test_workspace_nav_links_leave_and_hides_unimplemented_tabs` (WS-10)
- `Tests.Feature.Operations.ControlCenterFirstPaintTest::test_control_center_ssr_caches_first_paint_sections_not_full_dashboard` (WS-10)
- `Tests.Feature.OperationsDashboardTest::test_admin_can_view_operations_dashboard` (WS-10)
- `Tests.Feature.OperationsDashboardTest::test_operations_dashboard_shows_interakt_template_configuration_health` (WS-10)
- `Tests.Feature.OperationsDashboardTest::test_operations_dashboard_shows_meta_flow_integration_card` (WS-10)
- `Tests.Feature.OperationsDashboardTest::test_sidebar_shows_operations_link_for_admin` (WS-10)
- `Tests.Feature.OperationsModelFoundationTest::test_open_kpi_excludes_waiting_customer_completed_and_hardware_cases` (WS-10)
- `Tests.Feature.OperationsModelFoundationTest::test_waiting_customer_cases_are_excluded_from_action_required_counts` (WS-10)
- `Tests.Feature.OperationsSupportOwnershipTest::test_invalid_serial_case_assigns_support_agent` (WS-10)
- `Tests.Feature.OperationsSupportOwnershipTest::test_offline_agent_skipped_for_invalid_serial_reassignment` (WS-10)
- `Tests.Feature.OperatorDashboardCacheTest::test_live_metrics_for_performs_no_slow_scalar_count_queries` (WS-10)
- `Tests.Feature.OperatorDashboardCacheTest::test_live_metrics_kpis_only_performs_no_slow_scalar_count_queries` (WS-10)
- `Tests.Feature.OperatorDashboardCacheTest::test_live_reverb_metrics_for_performs_no_slow_scalar_count_queries` (WS-10)
- `Tests.Feature.OrderIdentityProtectionTest::serial_correction_remains_locked_after_correction` (WS-10)
- `Tests.Feature.OrderIdentityProtectionTest::timeline_includes_initial_protection_event_only_once_per_field` (WS-10)
- `Tests.Feature.Platform.MissionControlCompletionTest::test_superadmin_mission_control_exposes_tools_catalog` (WS-10)
- `Tests.Feature.Platform.MissionControlCompletionTest::test_superadmin_workspace_nav_includes_operations_and_platform_health` (WS-10)
- `Tests.Feature.Platform.PlatformCacheAuditReproductionTest::test_reproduce_clear_load_refresh_reload_and_capture_writers` (WS-10)
- `Tests.Feature.Platform.SnapshotCacheRegenerationPipelineTest::test_optimize_clear_style_cache_flush_does_not_persist_false_critical_from_missing_cache_heartbeat` (WS-10)
- `Tests.Feature.Platform.SnapshotCacheRegenerationPipelineTest::test_second_refresh_after_flush_keeps_non_critical_when_durable_heartbeat_exists` (WS-10)
- `Tests.Feature.PlatformIdentityTest::test_changelog_page_shows_empty_state_when_release_notes_missing` (WS-10)
- `Tests.Feature.PresenceEngineTest::test_superadmin_login_does_not_create_workforce_session` (WS-10)
- `Tests.Feature.ProductOrderRequestSerialExclusionTest::test_rd_order_missing_serial_still_shows_request_serial_action` (WS-10)
- `Tests.Feature.QueueIntegrityLiveRefreshTest::test_manual_assignment_broadcasts_remove_from_ready_queue` (WS-10)
- `Tests.Feature.RadiumBox.BackfillRadiumBoxOrdersCommandTest::test_order_with_manual_serial_and_missing_device_model_is_skipped_when_device_model_assigned` (WS-10)
- `Tests.Feature.RadiumBox.BackfillReadyQueueCommandTest::test_evaluates_eligibility_for_validated_unassigned_case` (WS-10)
- `Tests.Feature.RefundRequestTest::test_dashboard_shows_refund_status_counts` (WS-10)
- `Tests.Feature.Refunds.RefundExecutionReferenceGuardTest::test_wallet_completion_ignores_manual_execution_reference_fields` (WS-10)
- `Tests.Feature.Refunds.RefundStatutoryAdjustmentTest::test_generated_credit_note_follows_ca_monthly_negative_offset_treatment` (WS-10)
- `Tests.Feature.RequestCorrectSerialTest::test_manual_request_correct_serial_persists_audit_and_timeline_entries` (WS-10)
- `Tests.Feature.ReturnExchange.OnlineReturnRefundAllocationExecutionTest::test_wallet_line_executes_via_radiumbox_client_with_idempotency` (WS-04)
- `Tests.Feature.ServiceCaseAssignmentTest::test_dashboard_displays_owner_first_name` (WS-10)
- `Tests.Feature.ServiceCaseStatusSupportAppointmentSyncTest::test_closed_incident_does_not_expose_active_scheduled_support_card` (WS-10)
- `Tests.Feature.ServiceCaseStatusSupportAppointmentSyncTest::test_closed_incident_hides_scheduled_support_card_after_status_sync` (WS-10)
- `Tests.Feature.StatutoryInvoice.EInvoiceIssuancePathTest::test_commerce_b2b_service_is_blocked_in_phase_a_and_queued_in_phase_b` (WS-10)
- `Tests.Feature.StatutoryInvoice.IrnInputSnapshotTest::test_commerce_ingest_preserves_valid_uqc_and_rejects_invalid` (WS-10)
- `Tests.Feature.StatutoryInvoice.IrnInputSnapshotTest::test_commerce_uqc_and_structured_billing_are_snapshotted_at_mint` (WS-10)
- `Tests.Feature.StatutoryInvoice.RdServiceNetPhase1CleanTest::test_invalid_gstin_and_missing_statutory_data_fail_closed` (WS-10)
- `Tests.Feature.StatutoryInvoice.ServiceGstSplitIssuanceTest::test_b2b_split_follows_seller_state_and_place_of_supply_not_billing_state` (WS-10)
- `Tests.Feature.StatutoryInvoice.ServiceStatutoryInvoiceReconciliationBacklogTest::test_rdservice_net_ra_prefix_orders_are_reconciled` (WS-10)
- `Tests.Feature.StatutoryInvoice.StatutoryInvoicePdfRegressionContractTest::test_contract_non_fitting_small_serial_set_is_all_or_nothing_to_annexure` (WS-10)
- `Tests.Feature.StatutoryInvoice.StatutoryInvoicePdfRegressionContractTest::test_contract_small_serial_set_renders_inline_only_when_measured_block_fits` (WS-10)
- `Tests.Feature.SupportAppointmentBookingTest::test_customer_360_hides_support_appointments_section_when_none_exist` (WS-10)
- `Tests.Feature.SystemSettingsTest::test_superadmin_can_view_notification_settings` (WS-10)
- `Tests.Feature.SystemSettingsTest::test_superadmin_can_view_settings` (WS-10)
- `Tests.Feature.TeamPerformanceIntelligenceTest::test_ira_generates_contextual_insights` (WS-11)
- `Tests.Feature.TeamPerformanceIntelligenceTest::test_team_member_sees_only_own_stats` (WS-11)
- `Tests.Feature.Todos.TodoControllerTest::test_layout_exposes_todo_modal_entry_points` (WS-10)
- `Tests.Feature.Todos.TodoControllerTest::test_sidebar_includes_todos_for_agent` (WS-10)
- `Tests.Feature.WaitingStateOwnershipTest::test_no_op_reassignment_does_not_create_audit_or_notification` (WS-10)
- `Tests.Feature.WhatsAppConversationFeatureTest::test_drawer_renders_open_in_interakt_link_without_message_text` (WS-10)
- `Tests.Feature.WhatsAppConversationFeatureTest::test_webhook_messages_produce_single_runtime_timeline_card` (WS-10)
- `Tests.Feature.WhatsAppTemplateDispatcherTest::test_timeline_shows_whatsapp_template_sent_event_without_message_body` (WS-10)
- `Tests.Feature.Workforce.LeaveOperationalImpactPhase2ATest::test_employee_with_workload_shows_warning_and_counts` (WS-10)
- `Tests.Feature.Workforce.WorkforceEventIsolationTest::test_reserved_event_types_exist_but_are_not_produced_yet` (WS-10)
- `Tests.Feature.WorkspaceActionDialogTest::test_agent_close_requires_transaction_id` (WS-10)
- `Tests.Feature.WorkspaceActionDialogTest::test_close_action_closes_service_case_with_remark_and_timeline` (WS-10)
- `Tests.Feature.WorkspaceActionDialogTest::test_device_case_missing_serial_still_shows_request_serial_number_action` (WS-10)
- `Tests.Feature.WorkspaceActionDialogTest::test_exception_id_generation_is_unique_per_type_and_day` (WS-10)
- `Tests.Feature.WorkspaceActionDialogTest::test_global_search_finds_service_case_by_exception_id` (WS-10)
- `Tests.Feature.WorkspaceActionDialogTest::test_hardware_case_still_requires_serial_verification_before_close` (WS-10)
- `Tests.Feature.WorkspaceActionDialogTest::test_legacy_resolve_route_now_closes_service_case` (WS-10)
- `Tests.Feature.WorkspaceRemarkActionTest::test_note_action_returns_dashboard_refresh_payload` (WS-10)
- `Tests.Feature.WorkspaceResolveCloseActionTest::test_admin_can_resolve_with_required_remark` (WS-10)
- `Tests.Feature.WorkspaceResolveCloseActionTest::test_agent_close_action_requires_remark_body_and_transaction_id` (WS-10)
- `Tests.Feature.WorkspaceResolveCloseActionTest::test_agent_resolve_action_requires_remark_body_and_transaction_id` (WS-10)
- `Tests.Feature.WorkspaceResolveCloseActionTest::test_close_action_returns_dashboard_refresh_payload_with_reopen_action_trigger` (WS-10)
- `Tests.Feature.WorkspaceResolveCloseActionTest::test_close_action_returns_service_case_timeline_and_header_refresh_payload` (WS-10)
- `Tests.Feature.WorkspaceResolveCloseActionTest::test_resolve_action_persists_mention_relationship_without_notification` (WS-10)
- `Tests.Feature.WorkspaceResolveCloseActionTest::test_resolve_action_returns_dashboard_refresh_payload` (WS-10)
- `Tests.Feature.WorkspaceResolveCloseActionTest::test_resolve_action_returns_service_case_timeline_and_header_refresh_payload` (WS-10)
- `Tests.Feature.WorkspaceResolveCloseActionTest::test_resolve_timeline_shows_remark_before_status_change` (WS-10)
- `Tests.Unit.ChannelIngest.ChannelIngestPayloadHasherTest::test_legacy_rdp_service_hash_matches_pre_v4_0_95_stored_hash_on_replay` (WS-10)
- `Tests.Unit.HardwareFulfilment.HardwareFulfilmentPrefixGuardTest::test_rbp_never_opens_hardware_fulfilment_even_with_physical_lines` (WS-10)
- `Tests.Unit.Notifications.DriverInstallationGuideEmailTemplateTest::test_template_renders_branded_production_content_with_primary_cta` (WS-10)
- `Tests.Unit.Notifications.NotificationAuthorityServiceTest::test_ira_telegram_bridge_respects_global_channel_toggle` (WS-10)
- `Tests.Unit.Operations.TeamAvailabilityOverviewOptimizationTest::test_operational_roster_includes_all_tracked_members_with_batched_queries` (WS-10)
- `Tests.Unit.Operations.TeamAvailabilityOverviewOptimizationTest::test_overview_reuses_single_team_member_query_and_batched_sessions` (WS-10)
- `Tests.Unit.OrderCompletionTooltipTest::test_transaction_assign_tooltip_includes_service_reference_and_assignee` (WS-10)
- `Tests.Unit.OrderProductOrderTest::test_non_rin_non_config_prefix_orders_are_not_hardware` (WS-10)
- `Tests.Unit.OrderProductOrderTest::test_rbp_is_never_hardware_even_if_config_lists_rbp` (WS-10)
- `Tests.Unit.StatutoryInvoice.EInvoiceCatalogUqcReadinessTest::test_p186_did_not_infer_pcs_or_nos_without_owner_decision` (WS-11)
- `Tests.Unit.StatutoryInvoice.EInvoiceCatalogUqcReadinessTest::test_p187_owner_decision_assigns_pcs_only_to_the_nine_hardware_skus` (WS-11)
- `Tests.Unit.StatutoryInvoice.StatutoryInvoicePdfSerialCapacityTest::test_multipage_invoice_keeps_six_serials_on_page_one_without_annexure` (WS-10)
- `Tests.Unit.StatutoryInvoice.StatutoryInvoicePdfSerialCapacityTest::test_ten_or_fewer_serials_use_main_page_only` (WS-10)

## ENVIRONMENT BLOCKED

- `Tests.Unit.Finance.CaMonthlyReportXlsxPackageValidatorTest::test_validator_flags_minimal_package_missing_excel_required_parts` — P-12-32 phpunit @27b03d19 → ERROR
