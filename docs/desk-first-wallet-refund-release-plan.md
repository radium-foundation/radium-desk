# Desk-first wallet refund — release plan (flag OFF)

**Prompt:** `RadiumDesk-P-12-10-004`  
**Flag:** `CENTRAL_WALLET_DESK_FIRST_WALLET_REFUND_ENABLED` (default `false`)  
**Spoke allowlist:** `CENTRAL_WALLET_DESK_FIRST_WALLET_REFUND_SPOKES` (default `rdservice.in`)

## Product contract

- Desk Central Wallet is the **only spendable authority** for new wallet refunds when desk-first is enabled.
- Spokes receive **audit** acknowledgement via `central_wallet_ledger_entry_id` (non-spendable on RDIN when `radium_desk_central_wallet` source is used).
- Legacy path (flag off) unchanged: spoke spendable credits per v4.1.23.

## Production preflight checklist

1. Deploy Desk build containing desk-first orchestrator, ledger credit service, recovery UI, and spoke policy.
2. Confirm `CENTRAL_WALLET_DESK_FIRST_WALLET_REFUND_ENABLED=false` after deploy.
3. Confirm `CENTRAL_WALLET_DESK_FIRST_WALLET_REFUND_SPOKES=rdservice.in` (do not add net/box until spoke audit handlers verified on prod).
4. Verify RDIN integration token and wallet refund API; smoke **without** flag: legacy path still works.
5. On RDIN: confirm `DeskWalletRefundCreditService` desk-authoritative branch is deployed (ledger id → non-spendable audit).
6. Run read-only: no orphan `pending_execution` refunds with CW credit and missing completion.
7. Operator runbook: `docs/desk-first-wallet-refund-state-machine.md`.

## Single-refund canary

1. Owner approval ref recorded.
2. Enable flag **only** on staging/UAT first; complete one RDIN wallet refund; verify:
   - Exactly one Desk CW credit (`business_reference` = refund ref).
   - RDIN `users_wallet` row `source_system=radium_desk_central_wallet` (or equivalent audit), spendable sum unchanged.
   - Refund `execution_reference_no` = `CW:{ledger_id}`.
3. Production: one low-amount RDIN refund with flag on; monitor 24h.
4. Expand `CENTRAL_WALLET_DESK_FIRST_WALLET_REFUND_SPOKES` only after per-spoke audit verification.

## Monitoring and recovery

- Refund detail **Desk-first recovery** banners (`DeskFirstWalletRefundRecoveryInspector`).
- Alerts: `pending_execution` + CW ledger credit older than 15m.
- Retry: **Complete** again (idempotent desk credit + spoke audit).
- Revoke: desk-first coordinator (RDIN-only revoke wiring in this release).

## Stop conditions

- Any duplicate Desk CW credit for same `business_reference`.
- Any RDIN spendable balance increase matching a desk-first refund amount.
- Spoke audit API returns 5xx after Desk credit posted → stop new completions; use recovery flow.
- RDNET/RadiumBox in allowlist before spoke audit contract deployed.

## Rollback

1. Set `CENTRAL_WALLET_DESK_FIRST_WALLET_REFUND_ENABLED=false`; `config:cache`.
2. New refunds revert to legacy spoke path automatically.
3. **In-flight** `pending_execution` with Desk credit: do not disable flag until operator completes or finance reverses CW credit per runbook.
4. Completed desk-first refunds: revoke uses desk-first coordinator regardless of flag.

## Dual-spend note (migration vs desk-first)

Balance **migration** cutover may credit Desk before spoke retirement; checkout lock enforcement on RDIN is **not** wired to `isUserCheckoutBlocked`.  
**Desk-first new refunds** do not use migration cutover; they use spoke **audit** rows excluded from spendable balance on RDIN (when authoritative handler is deployed). Do not conflate the two paths.

## Prerequisites (no secrets in docs)

- Order-bound Desk customer with resolvable CWID (`CashfreeCentralCustomerBinder`).
- Per-spoke wallet refund integration configured on Desk.
- RDIN desk-authoritative wallet refund handler on spoke (required for default allowlist).
