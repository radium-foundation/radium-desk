# P15-08-041 — Read-only post-apply reconciliation

Canvas: [`p15-08-041-post-apply-reconciliation.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p15-08-041-post-apply-reconciliation.canvas.tsx)

Inspected 2026-08-15 after apply of `tier1-rehearsal-20260815-145625-46fb82`. Snapshot for that generation: **2026-08-15T20:26:26+05:30**. No extract, transport, apply, or writes.

## Safety

| Check | Result |
|---|---|
| VPS dark | `dark=true`, `active=[]` |
| DNS `desk.radiumbox.com` / `radiumbox.com` | Cloudflare `104.21.42.236`, `172.67.212.65` — **not** VPS `148.113.8.82` |
| Unexpected checkpoint generation | **none** (only `46fb82` and leftover `90fe9d` on zero-delta tables) |
| 90fe9d inbox SHA (VPS and Hostinger) | `db80fcc1f65dd10726547f31b88427580c3b4f3fd6fa3904fca1ad008580b518` |
| 46fb82 inbox SHA (VPS) | `ffd2152e268c86cc7d0fbebdb8c202257b0b582629d6290672ec888e84eb5580` |

Count equality does **not** prove full sync. Classification below uses PK ranges vs checkpoint `last_id` and `updated_at` vs snapshot.

## Drift vs Gate 3

Live Hostinger continued after Gate 3. Current source-ahead **counts** (Hostinger − VPS):

| Table | Gate 3 | Now | New IDs `> VPS last_id` | Updates `id≤last_id` and `updated_at>snapshot` | Soft deletes |
|---|---|---|---|---|---|
| orders | +16 | **+19** (38612 vs 38593) | **19** (38806–38824), all `created_at>snapshot` | **15** | 0 / 0 |
| incidents | +16 | **+19** (39719 vs 39700) | **19** (39815–39833), all after snapshot | **1** | new 0; old 25 both sides |
| cashfree_webhook_logs | +20 | **+25** (47151 vs 47126) | **25** (47127–47151) | 0 | n/a |
| finance_journals | +16 | **+19** (12286 vs 12267) | **19** (12268–12286) | 0 | n/a |
| finance_journal_lines | +32 | **+38** (24572 vs 24534) | **38** (24535–24572) | 0 | n/a |
| bonvoice_webhook_logs | +2 | **+2** (25465 vs 25463) | **2** (25464–25465) | 0 | n/a |
| bonvoice_call_events | +2 | **+2** (24972 vs 24970; max 24976 vs 24974) | **2** (24975–24976) | 0 | n/a |
| incident_bonvoice_call_links | (not listed) | **0** (12528 = 12528, max 12531 both) | 0 | 0 | n/a |
| users | — | count **equal** 17; Hostinger `max(updated_at)` 20:56:53 vs VPS 20:25:46 | 0 new IDs | **existing-row updates** | 0 |
| reference_sequences.sc | 39870 vs 39854 | **39873 vs 39854** | n/a (counter) | `updated_at` 20:56:44 vs 20:24:37 | n/a |

All listed count gaps are **new rows after the 46fb82 snapshot** (and after VPS `last_id`), not missing historical rows. Orders also have **15 in-watermark updates** that counts do not show. Soft-delete counts match for incidents (25).

## FK for a remaining delta

Would require parents **before** children, FK checks on:

1. **orders** (38806–38824) before **incidents** (39815–39833). All 19 new incidents reference those new order IDs. `created_by` max=1 (already on VPS).
2. **bonvoice_webhook_logs** (25464–25465) before **bonvoice_call_events** (24975–24976).
3. **finance_journals** (12268–12286) before **finance_journal_lines** (24535–24572). All 38 new lines reference the 19 new journals.
4. **cashfree_webhook_logs** after **orders** and **incidents** (existing `depends_on`).
5. **incident_bonvoice_call_links**: no new rows; no extra parent required for links.

Existing VPS users/device_models/finance_accounts cover the new children inspected here.

## `reference_sequences.sc`

- VPS `current_value=39854`, `updated_at=2026-08-15 20:24:37` (inside snapshot).
- Hostinger `current_value=39873`, `updated_at=2026-08-15 20:56:44`.
- Delta **+19** matches 19 new incidents (SC allocation on live source).

**Safe LCDS handling:** next extract of `reference_sequences` then apply uses `GREATEST` merge (`TableDeltaApplier::mergeReferenceSequence`). That raises VPS to 39873 and never lowers it. Do **not** manually SET the counter. Do **not** copy Hostinger over VPS if VPS were ever higher. Keep VPS **dark** until merge: if VPS allocated SC numbers from 39854 it would collide with Hostinger 39855–39873.

## Checkpoints (every Tier-1 table)

**46fb82** (applied this generation): orders, users, permissions, roles, reference_sequences, incidents, cashfree_webhook_logs, incident_bonvoice_call_links, bonvoice_webhook_logs, bonvoice_call_events, service_case_close_*, refund_requests, commercial_service_restorations, finance_journals, finance_journal_lines, ira_memories.

**90fe9d leftover** (0-row extract this generation, watermark unchanged): device_models, device_model_aliases, finance_accounts, finance_cash_accounts, finance_expense_categories, finance_payment_methods, finance_settings, model_has_*.

**No checkpoint file:** finance_bank_accounts, customer_data_corrections, customer_data_correction_items, cash_book_entries, finance_expenses (empty on both hosts).

Full count/`max(updated_at)` grid is in the canvas.
