# Cursor prompt log (Radium Desk)

Authoritative numbering for `RadiumDesk-P-*` IDs remains in `docs/cursor-prompt-ledger.md`. This log records prompts that explicitly requested `cursor-prompt-log.md`.

| ID | Date | Title | Notes |
|----|------|-------|-------|
| RadiumDesk-P-07-09-157 | 2026-09-11 | BonVoice webhook failure investigation (last 24h) | Read-only production KVM. Watchdog `bonvoice:webhook_failures` count **0**; 1512/1512 processed in rolling 24h. No recovery/replay. Last terminal failure log **#17721** (2026-08-07, `2:NO_CHANNEL`, missing callID). |
| RadiumDesk-P-07-09-247 | 2026-09-11 | Telegram KVM8 watchdog/classifier semantic correction deploy | Next unused after irn-foundation P-07-09-246. Queue/RadiumBox/Cashfree alert semantics only. Expected: ~7–8 actionable DLQ + ~61 historical stored; 6 current KVM8 RadiumBox FAILED + ~30 historical. No DLQ delete, no Cashfree replay, no capped-order recovery. |

Do not renumber or overwrite earlier rows. Append only.
