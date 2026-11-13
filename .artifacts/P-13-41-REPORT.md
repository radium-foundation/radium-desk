# P-13-41 — Stale UI assertions (matrix rows 125, 202)

| Field | Value |
| --- | --- |
| Global Prompt ID | **P-13-41** |
| Cursor ledger ID | **RadiumDesk-P-13-41** |
| Worktree | `.worktrees/p13-41-stale-ui` @ **`6f29f4a4`** |
| Wallet | **`d9481615`** preserved on primary clone |

---

## Root cause

| Row | Test | Classification | Evidence |
| ---: | --- | --- | --- |
| **125** | `ProductOrderRequestSerialExclusionTest::test_rd_order_missing_serial_still_shows_request_serial_action` | **Stale assertion (TEST)** | `canShowAction` **true**; HTML includes `data-workspace-trigger="request-serial"`; C360 quick-action label is **Request Serial** (not **Request Serial Number**) |
| **202** | `WorkspaceRemarkActionTest::test_note_action_returns_dashboard_refresh_payload` | **Stale assertion (TEST)** | Dashboard `replace_row` renders `service-case-row` partial without inline workspace triggers; `DashboardServiceCasesTest` asserts **no** `data-workspace-trigger="remark"` on dashboard |

**Application defect:** **None** — behavior matches current dashboard/C360 UX.

---

## Changes (test-only)

1. Row **125:** `assertSee('Request Serial Number')` → `assertSee('Request Serial')`.
2. Row **202:** Assert dashboard row identity + **absence** of remark trigger; assert remark persisted (aligned with `WorkspaceResolveCloseActionTest` dashboard row patterns).

---

## PHPUnit (`bin/phpunit-isolated`, PHP 8.5)

| Command | Result | Assertions | Duration |
| --- | --- | ---: | ---: |
| Filter row 125 | **PASS** 1/1 | 4 | 2512 ms |
| `ProductOrderRequestSerialExclusionTest` class | **PASS** 7/7 | 20 | 7869 ms |
| Filter row 202 | **PASS** 1/1 | 17 | 2463 ms |
| `WorkspaceRemarkActionTest` class | **PASS** 8/8 | 82 | 8668 ms |

Full suite: **NOT RUN**.

---

## Risks

- Register CSV still **STILL FAILING** until a register-reconcile prompt (out of scope).
- C360 label copy may change again — tests should track user-visible strings in quick-actions, not legacy phrasing.

---

*End of P-13-41 report.*
