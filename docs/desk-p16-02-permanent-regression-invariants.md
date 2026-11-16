# Desk P-16-02 — permanent regression invariants

**Risk:** HIGH · **PHPUnit group:** `permanent_regression` · **PR:** [#277](https://github.com/radium-foundation/radium-desk/pull/277)

These invariants are enforced by focused tests (not weakened). Full-suite evidence from P-15-44 @ `3ad4154cb` is preserved; Owner re-run uses P1010 after grant only.

## 1. Unpaid commerce must not open hardware fulfilment

Unpaid or void commerce orders must not create a `hardware_fulfilments` row. Repeat channel ingest stays idempotent on commerce only.

| Test | Class |
|------|--------|
| `test_unpaid_commerce_does_not_open_fulfilment_and_reingest_is_idempotent` | `HardwareFulfilmentIngestReadyTest` |
| `test_unpaid_rbp_does_not_open_hardware_fulfilment` | `HardwareRbpAutomaticFulfilmentJourneyTest` |
| `test_concurrent_unpaid_rbp_channel_ingest_creates_one_commerce_without_fulfilment` | `HardwareRbpChannelIngestMysqlConcurrencyTest` (MariaDB when disposable DB up) |

**Desk payment architecture:** Channel ingest records commerce payment state; fulfilment opens only on verified paid journeys. This task does **not** change payment or Cashfree code.

## 2. Manual reassignment to same agent upgrades Auto → Manual

When an operator manually reassigns to the **current** assignee and origin is **Auto**, Desk must persist **Manual** (ready-queue protection) with `manual_reassign` audit context.

| Test | Class |
|------|--------|
| `test_manual_assign_to_same_assignee_persists_manual_origin` | `ManualAgentOwnershipWorkflowTest` |

## 3. Protected Manual same-assignee reassign is a true no-op

When ownership already has a **protected** origin (e.g. **Manual**), redundant reassign to the same user must not emit duplicate reassignment audits or notifications.

| Test | Class |
|------|--------|
| `test_no_op_reassignment_does_not_create_audit_or_notification` | `WaitingStateOwnershipTest` |

## Fast validation (Cursor / pre-commit)

From repo root (worktree with vendor installed):

```bash
./vendor/bin/phpunit --group permanent_regression
```

MariaDB concurrent row skips cleanly when `127.0.0.1:33118` is unavailable (report BLOCKED, not PASS).