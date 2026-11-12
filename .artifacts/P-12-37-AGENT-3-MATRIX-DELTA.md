# P-12-37 — Matrix delta (Agent 3 @ `457b64fa`)

**Merged:** PR **#183** → `main` @ **`2ca010b42cc7587726d04278f7f05d7b85bd40a0`** (squash; CI rip + release-rip pass, 2026-11-12).

Authoritative baseline: **P-12-32** @ `27b03d19` / CSV in this folder. Records **Agent 3** status changes for workspace close/resolve feature tests only.

| Matrix method (stable key) | WS | Prior status | Post Agent 3 | Evidence |
| --- | --- | --- | --- | --- |
| `Tests.Feature.WorkspaceResolveCloseActionTest::test_resolve_action_returns_service_case_timeline_and_header_refresh_payload` | WS-10 | STILL FAILING | **VERIFIED FIXED** | Focused suite 40/40 @ `457b64fa`, `php -n` |
| `Tests.Feature.WorkspaceResolveCloseActionTest::test_close_action_returns_service_case_timeline_and_header_refresh_payload` | WS-10 | STILL FAILING | **VERIFIED FIXED** | same |
| `Tests.Feature.WorkspaceResolveCloseActionTest::test_resolve_action_returns_dashboard_refresh_payload` | WS-10 | STILL FAILING | **VERIFIED FIXED** | same |
| `Tests.Feature.WorkspaceResolveCloseActionTest::test_close_action_returns_dashboard_refresh_payload_with_reopen_action_trigger` | WS-10 | STILL FAILING | **VERIFIED FIXED** | same |
| `Tests.Feature.WorkspaceResolveCloseActionTest::test_agent_resolve_action_requires_remark_body_and_transaction_id` | WS-10 | STILL FAILING | **VERIFIED FIXED** | same |
| `Tests.Feature.WorkspaceResolveCloseActionTest::test_agent_close_action_requires_remark_body_and_transaction_id` | WS-10 | STILL FAILING | **VERIFIED FIXED** | same |
| `Tests.Feature.WorkspaceResolveCloseActionTest::test_admin_can_resolve_with_required_remark` | WS-10 | STILL FAILING | **VERIFIED FIXED** | same |
| `Tests.Feature.WorkspaceResolveCloseActionTest::test_resolve_action_persists_mention_relationship_without_notification` | WS-10 | STILL FAILING | **VERIFIED FIXED** | same |
| `Tests.Feature.WorkspaceResolveCloseActionTest::test_resolve_timeline_shows_remark_before_status_change` | WS-10 | STILL FAILING | **VERIFIED FIXED** | same |
| `Tests.Feature.WorkspaceActionDialogTest::test_close_action_closes_service_case_with_remark_and_timeline` | WS-10 | STILL FAILING | **VERIFIED FIXED** | same |
| `Tests.Feature.WorkspaceActionDialogTest::test_exception_id_generation_is_unique_per_type_and_day` | WS-10 | STILL FAILING | **VERIFIED FIXED** | same |
| `Tests.Feature.WorkspaceActionDialogTest::test_device_case_missing_serial_still_shows_request_serial_number_action` | WS-10 | STILL FAILING | **VERIFIED FIXED** | same |
| `Tests.Feature.WorkspaceActionDialogTest::test_global_search_finds_service_case_by_exception_id` | WS-10 | STILL FAILING | **VERIFIED FIXED** | same |
| `Tests.Feature.WorkspaceActionDialogTest::test_legacy_resolve_route_now_closes_service_case` | WS-10 | STILL FAILING | **VERIFIED FIXED** | same |
| `Tests.Feature.WorkspaceActionDialogTest::test_agent_close_requires_transaction_id` | WS-10 | STILL FAILING | **VERIFIED FIXED** | same |
| `Tests.Feature.WorkspaceActionDialogTest::test_hardware_case_still_requires_serial_verification_before_close` | WS-10 | STILL FAILING | **VERIFIED FIXED** | same |
| `Tests.Feature.WorkspaceActionDialogTest::test_dashboard_rows_open_customer360_without_actions_column` | WS-10 | (vite / dashboard) | **VERIFIED FIXED** | `withoutVite()` harness; same run |

**Unchanged in Agent 3 scope (already PASS @ P-12-32):**

- `WorkspaceActionDialogTest::test_assign_action_requires_remark_and_assignee` — VERIFIED FIXED
- `WorkspaceResolveCloseActionTest::test_dashboard_excludes_closed_cases_from_pending_admin_filter` — VERIFIED FIXED

## Count impact (Agent 3 owned slice)

| Metric | Before @ P-12-32 | After Agent 3 |
| --- | ---: | ---: |
| STILL FAILING → VERIFIED FIXED (matrix rows) | 16 failing in owned classes | **+17** reclassified |
| Agent 3 focused methods passing | 23/40 (coordinator baseline) | **40/40** |
