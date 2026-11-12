# P-12-37 — Ownership manifest (no file overlap)

| Agent | Worktree | Branch | Start HEAD | Owned tests (matrix) | Owned source paths (expected) | Exclusions |
| --- | --- | --- | --- | --- | --- | --- |
| **1 WS-10 Dashboard** | `.worktrees/p12-37-ws-10-operations-sidebar` | `feature/p12-37-ws-10-operations-sidebar` | `fafec714` | 7 rows: `AdministrationHomeTest` (2), `OperationsDashboardTest` (4), `AutomationOperationsDashboardTest` (1) | `tests/Feature/AdministrationHomeTest.php`, `tests/Feature/OperationsDashboardTest.php`, `tests/Feature/AutomationOperationsDashboardTest.php`; possible shared sidebar views | **No** `DashboardServiceCasesTest` (P-12-36 / PR #181) |
| **2 WS-04 OPM** | `.worktrees/p12-37-ws-04-opm-tail` | `feature/p12-37-ws-04-opm-tail` | `fafec714` | 1 row: `OnlineReturnRefundAllocationExecutionTest::test_wallet_line_executes_via_radiumbox_client_with_idempotency` | `tests/Feature/ReturnExchange/OnlineReturnRefundAllocationExecutionTest.php`; **STOP** if `app/` wallet/spoke changes required | Wallet WIP `d9481615` read-only |
| **3 Orders/Fulfilment** | `.worktrees/p12-37-orders-workspace-close` | `feature/p12-37-orders-workspace-close` | `fafec714` | 16 rows: `WorkspaceResolveCloseActionTest` (9), `WorkspaceActionDialogTest` (7) | `tests/Feature/WorkspaceResolveCloseActionTest.php`, `tests/Feature/WorkspaceActionDialogTest.php` | No dashboard snapshot / classifier (Agent 1) |
| **4 Test Environment** | `.worktrees/p12-37-test-env` | `feature/p12-37-test-env` | `fafec714` | 2 ENVIRONMENT BLOCKED matrix rows + related class failures | `tests/Unit/Finance/CaMonthlyReportXlsxPackageValidatorTest.php`, `tests/Feature/StatutoryInvoice/StatutoryInvoicePdfCustomerFacingSkuTest.php`; docs/CI only | **No** test weakening / no `@skip` without policy |
| **5 Reviewer** | `.worktrees/p12-37-reviewer` | `feature/p12-37-reviewer-readonly` | `fafec714` | — (read-only) | — | No commits |

## Active parallel (do not touch)

| Prompt | Worktree | Files |
| --- | --- | --- |
| P-12-36 | `.worktrees/p12-36-dashboard-service-cases` | `tests/Feature/DashboardServiceCasesTest.php`, `.artifacts/P-12-36-REPORT.md` |
| Wallet WIP | Main checkout + `.worktrees/p30-*` @ `d9481615` | `feature/p18-33-unified-wallet-display` |

## Conflict analysis

- **Git worktrees:** Six new P-12-37 trees + existing P-12-36; no branch checkout on wallet main.
- **DB/ports:** PHPUnit `RefreshDatabase` per process; no shared MySQL port assumed for focused runs.
- **Vendor:** Symlinked `vendor` → main repo `vendor` (read-only for deps).
- **Duplicates:** `DashboardServiceCasesTest` rows assigned only to P-12-36 (6 rows), excluded from Agent 1.
