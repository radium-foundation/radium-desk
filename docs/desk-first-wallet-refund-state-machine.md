# Desk-first wallet refund — durable execution state machine

**Prompt:** `RadiumDesk-P-04-10-191` / `RadiumDesk-P-12-10-004`  
**Flag:** `CENTRAL_WALLET_DESK_FIRST_WALLET_REFUND_ENABLED` (default `false`)  
**Spoke allowlist:** `CENTRAL_WALLET_DESK_FIRST_WALLET_REFUND_SPOKES` (default `rdservice.in`)

## States (refund_requests.status)

| Status | Meaning |
|--------|---------|
| `pending_execution` | Wallet refund approved; execution may be partial (Desk CW credit posted, spoke audit pending). |
| `completed` | Desk CW credit **and** spoke audit acknowledgement recorded; execution fields set (`CW:{ledger_id}`). |

No new DB status column: partial progress is inferred from **posted CW credit** (`business_reference` = refund `reference_no`) while status remains `pending_execution`.

## Transitions (complete flow)

```
pending_execution
  │  (1) lock + validate
  │  (2) ensureDeskCreditCommitted  [own DB txn — idempotent]
  │  (3) ensureSpokeAuditRecorded   [HTTP — no DB txn held]
  │  (4) lock + mark completed      [own DB txn]
  ▼
completed
```

### Invariants

1. At most **one** CW **credit** per refund (`business_reference` + `refund_requests:{id}` source reference).
2. After step (2) commits, transient spoke errors **must not** roll back the credit.
3. Step (3) is retried with the same `central_wallet_ledger_entry_id`; spoke uses audit idempotency on `desk_refund_reference`.
4. Step (4) runs only after (2)+(3) succeed; concurrent completes: one winner, others fail closed on status guard.
5. **Never** dispatch `RefundCompleted` or show `refund-completed` without (3) success.

## Revoke (desk-first)

```
completed (desk-first: execution_reference_no = CW:{credit_id})
  │  (A) reverseDeskCentralWalletCredit [Debit — idempotent source_reference]
  │  (B) retireSpokeAudit               [HTTP — audit row, non-spendable]
  │  (C) finalize revoke                [existing RefundRevokeService txn]
  ▼
revoked
```

- CW debit fails closed on insufficient spendable balance (reservations respected).
- Original credit wallet must match order-bound customer CWID.
- Spoke path retires `radium_desk_central_wallet` audit rows without spendable debits.

## Operator recovery (supported)

`DeskFirstWalletRefundRecoveryInspector` + refund detail alerts:

| Situation | UI signal | Operator action |
|-----------|-----------|-----------------|
| CW credit posted, spoke audit pending | Yellow **Desk-first recovery** banner | Click **Complete** again (same refund). |
| CW revoke debit posted, spoke retirement pending | Yellow **Desk-first revoke recovery** banner | Click **Revoke** again (same outcome/reason). |
| Revoke attempt `wallet_reversed`, finalize pending | Revoke recovery banner | **Revoke** again (no duplicate CW debit). |

Partial revoke persists `RefundRevocationAttempt` as `wallet_reversed` when the CW debit succeeds before spoke retirement fails.

## Dual-spend safety (desk-first vs migration)

| Path | Desk spendable credit | Spoke spendable credit | Mitigation |
|------|----------------------|------------------------|------------|
| **Desk-first (new refund)** | Yes (authoritative) | No — RDIN audit via `central_wallet_ledger_entry_id` / `radium_desk_central_wallet` | `DeskFirstWalletRefundSpokePolicy` defaults to RDIN only |
| **Legacy wallet refund** | No | Yes (spoke ledger) | Flag off |
| **Pilot refund migration** | Yes after cutover | Retired after lock+credit sequence | Separate gates; not used for new refunds |

Do not add `rdservice.net` or `radiumbox.com` to the spoke allowlist until each spoke rejects or non-spendable-records desk-authoritative audit requests in production.

## Failure-injection coverage (automated)

| Scenario | Completion | Revocation |
|----------|------------|------------|
| CW commit, Desk state not saved | Ledger row is source of truth; retry **Complete** | Committed debit via `refund-revoke:{id}`; retry **Revoke** |
| State saved, spoke not called | N/A (no completion attempt row) | `wallet_reversed` without spoke → retry **Revoke** |
| Spoke timeout after spoke processed | Idempotent spoke + single CW credit | Idempotent audit retirement |
| Spoke OK, Desk finalize not run | Orchestrator-equivalent path then **Complete** | N/A |
| Permanent spoke failure | Stays `pending_execution` | Stays revocable, not `revoked` |
| Process exit between CW and spoke | Same as timeout/retry rows | Inspector banner when debit exists |

**Not auto-resolved:** Permanent spoke errors (404/422) require integration fix before retry will succeed. CW insufficient balance on revoke requires funds/reservation release per existing policy.

**Revoke routing:** Refunds with persisted `execution_reference_no` = `CW:{ledger_id}` always use the Desk-first revoke coordinator, even when `desk_first_enabled` is false (flag gates **new** completions only).
