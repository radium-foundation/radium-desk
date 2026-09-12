# Cursor prompt log (Radium Desk)

Authoritative numbering for `RadiumDesk-P-*` IDs remains in `docs/cursor-prompt-ledger.md`. This log records prompts that explicitly requested `cursor-prompt-log.md`.

| ID | Date | Title | Notes |
|----|------|-------|-------|
| RadiumDesk-P-07-09-280 | 2026-09-12 | Wallet refund → automated RadiumBox wallet credit on completion | Companion `radiumbox.com-P-11-09-02`. Desk `WalletRefundExecutor` posts idempotent wallet credits to Box when `RADIUMBOX_WALLET_REFUND_CREDIT_ENABLED=true`. Manual wallet completion preserved when disabled. Not deployed. |
| RadiumDesk-P-07-09-281 | 2026-09-12 | Production deploy wallet refund integration | Companion `radiumbox.com-P-11-09-03`. Overlay `4773fffa` to KVM8. Flag enabled. No production financial E2E. |

Do not renumber or overwrite earlier rows. Append only.
