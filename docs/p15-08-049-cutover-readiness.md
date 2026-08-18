# P15-08-049 — Cutover-readiness after 8a90b6

Canvas: [`/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p15-08-049-cutover-readiness.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p15-08-049-cutover-readiness.canvas.tsx)

Read-only. Inspected 2026-08-15 after apply of `tier1-rehearsal-20260815-160106-8a90b6` (snapshot **2026-08-15T21:31:07+05:30**). No extract, transport, apply, checkpoint edits, DNS change, or `deskd`.

## Classification

**A. READY FOR FINAL DELTA**

Not **B (DNS cutover)**: Hostinger is still live and ahead. VPS `sc` is **39883** vs source **39891**. Cutting DNS now would drop 8 orders / 8 incidents / 11 cashfree logs / 8 journals / 16 journal lines plus in-watermark order and user updates, and would risk SC collisions if VPS allocated from 39883.

Not **C (blocked)**: no unexpected checkpoint generation, VPS remains dark, DNS does not point at the VPS, soft-delete counts match, and every missing child row’s parents are either already on VPS or are in the same post-watermark ID set (orders before incidents before cashfree; journals before lines). One authorized incremental using existing VPS checkpoints and GREATEST `sc` merge is sufficient. Do **not** create that generation in this step.

## Safety

| Check | Result |
|---|---|
| VPS dark | `dark=true`, `active=[]`, process_count 29 |
| DNS `desk.radiumbox.com` | `187.127.183.72` (Hostinger production) — **not** VPS `148.113.8.82` |
| DNS `radiumbox.com` | Cloudflare `104.21.42.236`, `172.67.212.65` — **not** VPS |
| Unexpected checkpoint generation | **none** (only `8a90b6`, `19cd9f`, `46fb82`, `90fe9d`) |
| Inbox SHAs Hostinger = VPS | all four match (see below) |

## Authoritative VPS checkpoints

27 files. `last_generation_id` only from the four known generations.

**8a90b6** (this apply): orders `last_id=38834`, incidents `39843`, cashfree `47161`, journals `12296`, journal_lines `24592`, users `17` / `updated_at=21:30:25`, permissions, roles, `reference_sequences` (`sc` 39883).

**19cd9f leftover** (0-row this generation): `bonvoice_webhook_logs` 25466, `bonvoice_call_events` 24977.

**46fb82 leftover**: links 12531, close outcomes/exceptions, refunds, ira, commercial restorations.

**90fe9d leftover**: device/finance master + permission pivots.

VPS `max(id)` equals checkpoint `last_id` on every drifted table.

## Inbox artifacts intact

| Generation | Manifest SHA-256 | VPS chunks |
|---|---|---|
| `90fe9d` | `db80fcc1f65dd10726547f31b88427580c3b4f3fd6fa3904fca1ad008580b518` | 108 |
| `46fb82` | `ffd2152e268c86cc7d0fbebdb8c202257b0b582629d6290672ec888e84eb5580` | 59 |
| `19cd9f` | `d1653e63412b1848fff0ae2e145e4cfdb24361f15da874f6228cf8a41d57dfa3` | 11 |
| `8a90b6` | `9da85f72d050a5020d58f822e2de88dbb0c5df00d874c007e55d79c7b113c845` | 9 |

Hostinger SHAs match. Hostinger also still has leftover `tier1-rehearsal-20260815-085306-a55099` (ignored; not on VPS).

## Hostinger vs VPS drift (now)

Count gaps equal new-ID counts. All new `created_at` values are after the 8a90b6 snapshot.

| Table | Count H−V | New IDs after last_id | In-watermark updates | Soft deletes |
|---|---|---|---|---|
| orders | +8 (38630 vs 38622) | **38835–38842** | **12** ids (≤38834, `updated_at` > 21:31:07) | 0 / 0 |
| incidents | +8 (39737 vs 39729) | **39844–39851** | 0 | 25 / 25 |
| cashfree_webhook_logs | +11 (47172 vs 47161) | **47162–47172** | 0 | n/a |
| finance_journals | +8 (12304 vs 12296) | **12297–12304** | 0 | n/a |
| finance_journal_lines | +16 (24608 vs 24592) | **24593–24608** | 0 | n/a |
| users | 0 (17=17) | none | **ids 1, 3, 12** | 0 / 0 |
| bonvoice_* / links | 0 | none | 0 | n/a |
| `sc` | n/a | n/a | **39891 vs 39883** (`updated_at` 21:54:32 vs 21:30:15) | n/a |

`sc` delta **+8** matches 8 new incidents.

## FK for currently missing rows

Parents required for this delta **do not yet exist on VPS** except where noted. They **would** exist if one incremental extract includes the parent tables before children (existing Tier-1 `depends_on` / apply order).

1. **orders 38835–38842** — `created_by`/`updated_by` in {1,3}; `device_model_id` in {1,2,3,4,null}. Users 1–17 and device_models 1–9 already on VPS.
2. **incidents 39844–39851** — each `order_id`/`order_record_id` is the matching new order 38835–38842. `created_by=1`; `assigned_to_user_id` in {3,12,null}; user 12 already on VPS. Soft-delete on these rows: none.
3. **cashfree 47162–47172** — `incident_id` is 39844–39851 **except 47163 → incident 39843**, which is **already on VPS**. Apply order remains cashfree after incidents.
4. **journal lines 24593–24608** — `journal_id` 12297–12304 (all new); `account_id` only 2 and 4 (already on VPS).
5. No new bonvoice events/logs/links. No extra Bonvoice parent required.

## Cashfree / RadiumBox reconciliation (read-only)

`remote_inspect.php --action=reconciliation-readonly`:

| | Hostinger | VPS |
|---|---|---|
| orders_count | 38630 | 38622 |
| cashfree_webhook_logs_count | 47172 | 47161 |

Gaps are the new IDs above. Did **not** run Cashfree recover or RadiumBox replay.

## What a final delta must include

Existing mutable cursor (`id > last_id OR updated_at > last_updated_at`) plus GREATEST `sc` merge:

- orders (new IDs **and** 12 in-watermark updates)
- incidents (new IDs)
- cashfree_webhook_logs
- finance_journals + finance_journal_lines
- users (ids 1, 3, 12)
- `reference_sequences` (`sc` 39883 → live source, currently 39891)

Full current Tier 1 is also safe (bonvoice and other tables likely 0-row). Keep VPS dark. Do not SET `sc` by hand. Hostinger will keep writing during that extract; leftover lag after it is the same class, not a blocker for authorizing one incremental.

Do **not** create or apply that generation in this step.
