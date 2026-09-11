# Cursor prompt log (Radium Desk)

Authoritative numbering for `RadiumDesk-P-*` IDs remains in `docs/cursor-prompt-ledger.md`. This log records prompts that explicitly requested `cursor-prompt-log.md`.

| ID | Date | Title | Notes |
|----|------|-------|-------|
| RadiumDesk-P-07-09-253 | 2026-09-11 | Establish safe QA environment for P-07-09-250 | Next unused after P-07-09-252. Disposable sqlite + loopback :8765. Browser QA PASS except live 429 (not observed locally). P-250 preserved uncommitted. No sale/commit/push/deploy. |
| RadiumDesk-P-07-09-254 | 2026-09-11 | Commit, push and deploy validated POS rapid-scan hardening | Next unused after P-07-09-253. After P-253 PASS. Commit/push P-250 only. Named-file overlay counter Blade to production. Cart-only verify. No sale/payment/invoice. |

Do not renumber or overwrite earlier rows. Append only.
