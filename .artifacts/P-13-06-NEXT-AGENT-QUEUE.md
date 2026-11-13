# P-13-06 — Next implementation-agent queue (plan only)

**Baseline:** `origin/main` @ **`b54b0a53`** after canonical register reconciliation PR lands.  
**Remaining WS-10 STILL FAILING:** **31** rows (+ **2** WS-11, **1** WS-04 protected).  
**Do not start implementation from this document** — dispatch separate global prompts.

---

## Excluded / unassigned until ownership is clear

| CSV lines | Test | Reason |
| ---: | --- | --- |
| 138 | `OnlineReturnRefundAllocationExecutionTest::test_wallet_line_executes_via_radiumbox_client_with_idempotency` | **WS-04** wallet execution — protected |
| 135–136 | `RefundExecutionReferenceGuardTest`, `RefundStatutoryAdjustmentTest` | Wallet-adjacent — protected with `feature/p18-33-unified-wallet-display` |
| 226–227 | `EInvoiceCatalogUqcReadinessTest` `test_p186_*`, `test_p187_*` | **WS-11** owner-decision UQC — not WS-10 shard |
| 2–4 | `AdministrationHomeTest` (2), `AgentDashboardOwnershipTest` (1) | Files changed post–P-12-32 log (**#184** / P-12-35) — **UNKNOWN** on current main; focused re-run gate before fix |
| 202 | `WorkspaceRemarkActionTest::test_note_action_returns_dashboard_refresh_payload` | Same workspace cluster as P-13-15 but **not** in 16-row stale set — needs isolated repro |

---

## P-13-17 — Incoming email intake race / exception surfacing

| Field | Value |
| --- | --- |
| Row IDs (CSV lines) | **80**, **81** |
| Methods | `IncomingEmailIntakePhase1Test::test_ingest_propagates_unrelated_query_exceptions`, `test_ingest_returns_existing_message_on_rfc_message_id_insert_race` |
| Root cause | **INFERRED** ingest transaction / duplicate-key race handling vs P-12-32 ERROR/FAIL |
| Ownership | `IncomingEmailIntake*` services, intake command, phase-1 test fixtures |
| Focused regression | Full `IncomingEmailIntakePhase1Test` |
| Dependencies | None with closed P-13-08..13 clusters |
| Overlap risk | **Low** |
| Risk | Normal |
| Parallel worktree | **Yes** |

---

## P-13-18 — Operations support ownership routing

| Field | Value |
| --- | --- |
| Lines | **110**, **111** |
| Methods | `OperationsSupportOwnershipTest::test_invalid_serial_case_assigns_support_agent`, `test_offline_agent_skipped_for_invalid_serial_reassignment` |
| Root cause | **INFERRED** support-agent selection / offline skip policy |
| Ownership | `OperationsSupportOwnershipTest`, assignment/support routing services |
| Focused regression | `OperationsSupportOwnershipTest` (full class) |
| Dependencies | Does not edit ready-queue broadcast (P-13-03 closed) |
| Overlap risk | Medium with assignment KPI work — avoid `ServiceCaseAssignmentService` unless repro demands |
| Risk | Normal |
| Parallel worktree | **Yes** |

---

## P-13-19 — Order identity protection and serial correction

| Field | Value |
| --- | --- |
| Lines | **115**, **116**, **137** |
| Methods | `OrderIdentityProtectionTest` (2), `RequestCorrectSerialTest::test_manual_request_correct_serial_persists_audit_and_timeline_entries` |
| Root cause | **INFERRED** identity lock + timeline dedupe + manual correction audit |
| Ownership | Order identity protection services, serial correction workspace actions |
| Focused regression | `OrderIdentityProtectionTest`, `RequestCorrectSerialTest` |
| Dependencies | Serial insight / workspace close paths (P-13-15 closed) — read-only conflict check |
| Overlap risk | Low |
| Risk | Normal |
| Parallel worktree | **Yes** |

---

## P-13-20 — Team availability overview query batching

| Field | Value |
| --- | --- |
| Lines | **221**, **222** |
| Methods | `TeamAvailabilityOverviewOptimizationTest::test_operational_roster_includes_all_tracked_members_with_batched_queries`, `test_overview_reuses_single_team_member_query_and_batched_sessions` |
| Root cause | **INFERRED** N+1 / roster materialization regression |
| Ownership | Team availability read models + overview controller |
| Focused regression | `TeamAvailabilityOverviewOptimizationTest` |
| Dependencies | Presence engine (line 124) separate — do not merge |
| Overlap risk | Low |
| Risk | Normal |
| Parallel worktree | **Yes** |

---

## P-13-21 — Customer-waiting automation cleanup

| Field | Value |
| --- | --- |
| Lines | **11**, **12**, **184** |
| Methods | `CustomerWaitingAppointmentRepairCommandTest::test_closed_incident_is_skipped`, `CustomerWaitingLegacyCleanupTest::test_cleanup_writes_timeline_entry_without_customer_notifications`, `WaitingStateOwnershipTest::test_no_op_reassignment_does_not_create_audit_or_notification` |
| Root cause | **INFERRED** automation command filters + no-op reassignment audit policy |
| Ownership | Customer-waiting commands, `WaitingStateOwnershipTest` |
| Focused regression | The three test classes |
| Dependencies | Incident status sync (P-13-09 closed) — no Blade edits |
| Overlap risk | Medium with assignment — bounded to waiting-state owner |
| Risk | Normal |
| Parallel worktree | **Yes** |

---

## P-13-22 — Channel ingest payload hash replay

| Field | Value |
| --- | --- |
| Lines | **213** |
| Methods | `ChannelIngestPayloadHasherTest::test_legacy_rdp_service_hash_matches_pre_v4_0_95_stored_hash_on_replay` |
| Root cause | **INFERRED** legacy hash algorithm drift |
| Ownership | Channel ingest hasher unit tests + hasher implementation |
| Focused regression | `ChannelIngestPayloadHasherTest` |
| Dependencies | P-13-08 statutory ingest closed — read-only |
| Overlap risk | Low |
| Risk | Normal |
| Parallel worktree | **Yes** |

---

## P-13-23 — Inventory POS operational workflow (single high-touch row)

| Field | Value |
| --- | --- |
| Lines | **91** |
| Methods | `InventoryPosOperationalWorkflowTest::test_operator_workflow_catalog_serial_lifecycle_sale_invoice_and_finance` |
| Root cause | **UNKNOWN** multi-stage workflow — statutory + serial + finance |
| Ownership | POS operational workflow, inventory catalog lifecycle |
| Focused regression | Method + class smoke |
| Dependencies | Statutory issuance (P-13-08 closed), hardware (P-13-11 closed) — high integration |
| Overlap risk | **High** — run alone or after P-13-17..22 |
| Risk | **High** |
| Parallel worktree | **Yes**, but prefer serial order after smaller clusters |

---

## P-13-24 — WS-11 catalog UQC readiness (non–WS-10)

| Field | Value |
| --- | --- |
| Lines | **226**, **227** |
| Methods | `EInvoiceCatalogUqcReadinessTest::test_p186_*`, `test_p187_*` |
| Root cause | **INFERRED** owner-decision PCS/NOS catalog policy + Imagick/phpunit harness |
| Ownership | Statutory catalog UQC unit tests — **not** WS-10 agent |
| Focused regression | `EInvoiceCatalogUqcReadinessTest` |
| Dependencies | WS-11 / owner approval |
| Overlap risk | Statutory issuance — coordinate with finance owner |
| Risk | High (tax catalog) |
| Parallel worktree | **Yes**, separate from WS-10 dashboard work |

---

*End of queue plan — informational; no agents dispatched.*
