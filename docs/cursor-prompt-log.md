# Cursor prompt log (Radium Desk)

Authoritative numbering for `RadiumDesk-P-*` IDs remains in `docs/cursor-prompt-ledger.md`. This log records prompts that explicitly requested `cursor-prompt-log.md`.

| ID | Date | Title | Notes |
|----|------|-------|-------|
| RadiumDesk-P-07-09-280 | 2026-09-12 | Wallet refund → automated RadiumBox wallet credit on completion | Companion `radiumbox.com-P-11-09-02`. Desk `WalletRefundExecutor` posts idempotent wallet credits to Box when `RADIUMBOX_WALLET_REFUND_CREDIT_ENABLED=true`. Manual wallet completion preserved when disabled. Not deployed. |
| RadiumDesk-P-07-09-281 | 2026-09-12 | Production deploy wallet refund integration | Companion `radiumbox.com-P-11-09-03`. Overlay `4773fffa` to KVM8. Flag enabled. No production financial E2E. |
| RadiumDesk-P-07-09-282 | 2026-09-12 | Fix production HTTP 500 on GET /finance/dashboard | Production `workspace-nav` called `route('finance.legacy-cash.index')` but `web.php` lacked the named route. Overlay restored `finance.legacy-cash.index`. Authenticated dashboard 200. Wallet/refund files untouched. |
| RadiumDesk-P-07-09-283 | 2026-09-12 | Customer 360 read-only Wallet Ledger | Companion `radiumbox.com-P-11-09-04`. `WalletLedgerReadService` + C360 tab gated by `finance.wallet.view`. Server-to-server Box read API. No wallet writes. |
| RadiumDesk-P-07-09-284 | 2026-09-12 | Restore purchasing routes after P-283 route-file regression | Surgical `routes/web.php` overlay: purchasing group + imports restored; `finance.legacy-cash.index` preserved. Named-file deploy + `route:cache`. Authenticated app pages verified 200. |
| RadiumDesk-P-07-09-285 | 2026-09-12 | Investigation-only: `/pos/counter` HTTP 500 | `pos.serials.match` missing; two customer routes also required. Investigation only. |
| RadiumDesk-P-07-09-286 | 2026-09-12 | Restore POS counter route dependencies | Three-route atomic overlay for counter page. `/pos/counter` 200 in production. |
| RadiumDesk-P-07-09-287 | 2026-09-12 | Restore POS statutory invoice routes | `pos.sales.statutory.pdf`, `.download`, `.email` restored. Sale show and PDF endpoints verified 200. No production email sent. |

Do not renumber or overwrite earlier rows. Append only.
