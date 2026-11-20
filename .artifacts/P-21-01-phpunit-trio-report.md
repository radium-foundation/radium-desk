# P-21-01 — Resolve three P-20-24 PHPUnit failures

| Field | Value |
|-------|--------|
| Task | **P-21-01** |
| Cursor ledger | `bd0ac5bd-88f0-48e0-aacd-66eca9be9aef` |
| Risk | **HIGH** (wallet refund guard) |
| Base | `c46d6cf6bac48e2bfe8696a50b9af0ffad890751` |
| Worktree | `.worktrees/p21-01-phpunit-trio` |

## Results

| Test | On c46d6cf6b | Classification | Fix |
|------|--------------|----------------|-----|
| `DashboardServiceCasesTest::test_dashboard_sla_alert_cards_filter_table` | **Reproduced** | Test assertion drift (`>1</div>` vs multiline KPI markup) | **VERIFIED** — SLA filter logic unchanged; test asserts `data-workspace="overdue"` |
| `HardwareFulfilmentWorkQueueTest::test_rde318421_show_is_display_only_and_does_not_duplicate` | **Reproduced** | Stale expectation post P-17-40 package-photo retirement | **VERIFIED** — test aligned to pickup UI + duplicate guards |
| `WalletRefundExecutionTest::test_already_closed_refund_cannot_be_recompleted` | **Reproduced** | App defect — silent no-op on `Closed` (P-18-37 idempotency) | **VERIFIED** — `ValidationException` on closed re-complete |

## Focused validation

- Trio tests: **3/3 PASS**
- `WalletRefundExecutionTest` full class: **21/21 PASS**
- `RefundCompletionPostCommitStabilityTest`: **9/9 PASS** (stability test updated for closed guard)
- `RefundCompletionMysqlConcurrencyTest`: **3/3 PASS** (~431s)
- Hardware package photo / pickup workflow filter: **11/11 PASS**
- Dashboard SLA filter tests: **2/2 PASS**
- Full suite: **NOT RUN**
- Production: **NOT deployed**