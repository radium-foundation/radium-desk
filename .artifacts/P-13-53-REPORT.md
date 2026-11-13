# P-13-53 — Row 135 Desk-authoritative wallet execution reference guard

| Global | **P-13-53** | Ledger | **RadiumDesk-P-13-53** |
| Baseline | **`f9a3fa5d`** |
| End (branch) | *(post-commit)* |

## Owner decision implemented

Redesigned row **135** to exercise **desk-first + desk-authoritative** wallet completion (Central Wallet ledger + spoke audit). **Did not** set `desk_authoritative_enabled => false` or restore legacy RadiumBox spendable path.

## Test change (only)

`RefundExecutionReferenceGuardTest::test_wallet_completion_ignores_manual_execution_reference_fields`:

- Desk-first flags on; synthetic CW customer + ledger path via `refunds.complete`.
- Asserts manual Desk `REF-*` cannot override persisted `CW:{ledger_id}` execution fields.
- Asserts legacy executor path rejected when desk-first off under authoritative policy.
- Duplicate complete → session error (pending guard).
- Class `setUp`: `withoutVite()` (harness only).

**Application code:** unchanged.

## Validation

| Scope | Exit | Result | ms |
| --- | ---: | --- | ---: |
| Row 135 | 0 | 1/1, 13 assert | 6571 |
| Full guard class | 0 | 7/7, 31 assert | 7989 |
| Completion gate + desk-first refund | 0 | 6/6 | 6563 |
| RB1010 authority filter (2) | 0 | 2/2 | 3247 |
| Pint (test file) | 0 | PASS | ~611 |
| Full suite | — | **NOT RUN** | — |

Full suite / deploy / prod: **NOT RUN** / **NO**.
