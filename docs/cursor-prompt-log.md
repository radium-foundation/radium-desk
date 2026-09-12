# Cursor prompt log (Radium Desk)

Authoritative numbering for `RadiumDesk-P-*` IDs remains in `docs/cursor-prompt-ledger.md`. This log records prompts that explicitly requested `cursor-prompt-log.md`.

Global IDs P-07-09-158–277 were assigned on other Desk worktrees (`fix/telegram-kvm8-alert-semantics`, `feat/irn-foundation-phase-a`) and are not duplicated here.

| ID | Date | Title | Notes |
|----|------|-------|-------|
| RadiumDesk-P-07-09-278 | 2026-09-12 | Deploy Legacy Cash overlay + STRICT production dry-run | Next unused after P-07-09-277. Named-file overlay of `03b37452`. Backup `20260912T095538Z`. Migration not run. Artisan `--json` dry-run twice matched approved totals. `--apply` / `--post-opening` not used. |
| RadiumDesk-P-07-09-279 | 2026-09-12 | Production Legacy Cash import + opening journal | Owner-authorized apply gate. Backup `20260912T110731Z`. Scoped migrate + permission + SELECT grant. 1765 legacy rows + opening journal JRN-2026-26845. |

Do not renumber or overwrite earlier rows. Append only.
