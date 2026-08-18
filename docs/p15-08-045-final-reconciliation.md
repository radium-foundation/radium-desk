# P15-08-045 — Read-only reconciliation after 19cd9f

Canvas: [`p15-08-045-final-reconciliation.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p15-08-045-final-reconciliation.canvas.tsx)

No extract, transport, apply, or writes. Snapshot of generation `tier1-rehearsal-20260815-154314-19cd9f`: **2026-08-15T21:13:15+05:30**.

## Safety

| Check | Result |
|---|---|
| VPS dark | `dark=true`, `active=[]` |
| DNS | `desk.radiumbox.com` / `radiumbox.com` → Cloudflare `104.21.42.236`, `172.67.212.65` — not VPS `148.113.8.82` |
| Checkpoint gens | only `19cd9f`, `46fb82`, `90fe9d` — **no unexpected generation** |
| Inbox SHAs (VPS = Hostinger) | `90fe9d` `db80fcc1…580b518`, `46fb82` `ffd2152e…eb5580`, `19cd9f` `d1653e…dfa3` |

## Remaining live drift (PK vs VPS `last_id`, `updated_at` vs snapshot)

| Table | Count H−V | New IDs after last_id | In-watermark updates | Soft deletes |
|---|---|---|---|---|
| orders | +3 (38621 vs 38618) | **38831, 38832, 38833** (all `created_at` after snapshot) | **14** ids | 0 |
| incidents | +3 (39728 vs 39725) | **39840–39842** | **7** ids | 25 both sides; new 0 |
| cashfree_webhook_logs | +3 (47160 vs 47157) | **47158–47160** | 0 | n/a |
| finance_journals | +3 (12295 vs 12292) | **12293–12295** | 0 | n/a |
| finance_journal_lines | +6 (24590 vs 24584) | **24585–24590** | 0 | n/a |
| users | 0 | 0 | **ids 1, 3** (`max(updated_at)` 21:28:39 vs VPS 21:13:13) | 0 |
| bonvoice_* / links | 0 | 0 | 0 | n/a |
| `sc` | n/a | n/a | **39882 vs 39879** | n/a |

VPS `max_id` equals checkpoint `last_id` on drifted tables. Count gaps equal new-ID counts. Only sequence is `sc` (+3 matches 3 new incidents).

## FK for remaining rows

All new incidents reference orders **38831–38833** (also new). `created_by=1` already on VPS. All 6 new journal lines reference journals **12293–12295**. No new call events/links. Cashfree 3 new rows should follow orders+incidents.

**Parents required for this delta do not yet exist on VPS** (the new orders/journals). They **would** exist in one incremental extract that includes those parent tables before children — same Tier-1 graph already deployed.

## Can ONE small incremental generation handle it?

**Yes**, with the existing pipeline and VPS checkpoints:

- Mutable cursor (`id > last_id OR updated_at > last_updated_at`) captures new IDs **and** the 14 order / 7 incident / 2 user in-watermark updates.
- Include at least: orders, incidents, cashfree_webhook_logs, finance_journals, finance_journal_lines, users, reference_sequences (GREATEST `sc` 39879→39882). Full current Tier 1 is also safe (other tables likely 0-row).
- Keep VPS dark. Do not SET `sc` by hand.
- Hostinger is still live: a few more rows may appear **during** that extract; that is the same lag class, not a blocker for one authorized incremental.

Do **not** create or apply that generation in this step.
