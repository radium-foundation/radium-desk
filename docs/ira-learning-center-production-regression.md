# IRA Learning Center — Production Regression Investigation

**Date:** 2026-08-06 (~10:06 IST)  
**Scope:** Root-cause investigation only — **no code changes**  
**Environment:** Production (`desk.radiumbox.com` via `tools/config.sh`)  
**Canvas:** none  

---

## Executive verdict

| Issue | Root cause | Bug? |
|-------|------------|------|
| **1 – Applied email stays in Needs Human** | Apply for **Docs** / **Assign** updates classification or owner **without** changing `status` away from `needs_review`. Needs Human is status-based, so the count stays 3. Matches current product docs for Docs. | **Product / UX expectation mismatch**, not cache failure |
| **2 – Fresh emails not arriving** | Intake is **healthy**. New mail is still ingested and auto-routed to `linked` / `ignored`. Needs Human is not growing because nothing new lands in `needs_review`. | **No intake outage** |

---

## Issue 1 — Applied email remains in Needs Human

### Observed on production (same moment)

| Signal | Value |
|--------|------:|
| Needs Human / DB `needs_review`+`failed` | **3** |
| Dashboard Needs Attention | **3** |
| Learning Center `needsHumanCount()` | **3** |
| All three equal | **true** |

Cache invalidation **does** run after Apply (`IncomingEmailLearningActionService::applyToMessages` → `forgetDashboardWidgetCache()`). Dashboard, Learning Center, and DB agree — the backlog really is still 3.

### Exact rows after recent Apply actions

| id | Apply (audit) | Time (IST) | Resulting `status` | `classification` | Other |
|----|---------------|------------|--------------------|------------------|-------|
| **178727** | `classification=docs`, scope `same_subject_pattern` | 10:04:16 | **`needs_review`** | **`docs`** | rule id 2 |
| **178723** | `assign` → user 4 | 10:05:19 | **`needs_review`** | `possible_sales_lead` | `learning_owner_user_id=4`, rule id 3 |
| **178731** | `assign` → user 6 | 09:29:29 | **`needs_review`** | `possible_sales_lead` | `learning_owner_user_id=6`, rule id 1 |

Also: `docs` + `needs_review` count = **1**; `docs` + `ignored` count = **0**.

**Evidence:** Apply succeeded (audit + rule + toast). Classification / assignee changed. Status did **not** leave Needs Human.

### Does Apply update only classification?

Depends on the action:

| Action | Updates | Changes `status`? | Leaves Needs Human? |
|--------|---------|-------------------|---------------------|
| **Assign** | `learning_owner_user_id`, IRA explainability fields, optional rule | **No** | **Yes (stays)** |
| **Classification → Support / Sales / Refund / Vendor / Docs** | `classification` + IRA fields + rule | **No** | **Yes (stays)** |
| **Classification / Move To → Promotion / Spam / Completed Automatically** | `classification`, `status=ignored`, `ignore_reason`, ignore stats | **Yes → `ignored`** | **No (removed)** |
| **Importance** | `importance` + IRA fields | **No** | **Yes (stays)** |
| **Ignore** (once / always / …) | `status=ignored`, classification, reason | **Yes → `ignored`** | **No (removed)** |

Authoritative code — `IncomingEmailLearningActionService::applyClassification()`:

```php
$shouldIgnore = in_array($classification, [
    IncomingEmailOperatorClassification::Promotion,
    IncomingEmailOperatorClassification::Spam,
    IncomingEmailOperatorClassification::Automatic,
], true);
// Docs is intentionally NOT in this list
```

Documented in `docs/ira-learning-center-ux-redesign.md`:

> Docs … **Does not auto-ignore** (unlike Promotion / Spam / Completed Automatically) — teach/label only.

Phase 1 doc already said Promotion / Spam / Completed Automatically remove from Needs Human; Assign / Importance / non-ignore classifications do not.

### Expected status after each action

| Operator action | Expected `status` after Apply | Expected Needs Human count |
|-----------------|-------------------------------|----------------------------|
| Assign | `needs_review` (or `failed` if it was failed) | Unchanged |
| Move → Promotions | `ignored` (`ignore_reason=promotions`) | Decrements |
| Move → Spam | `ignored` (`ignore_reason=spam`) | Decrements |
| Move → Completed Automatically | `ignored` (`ignore_reason=auto_responder`) | Decrements |
| Classification → Docs | `needs_review` (label only) | Unchanged |
| Classification → Support / Sales / Refund / Vendor | `needs_review` | Unchanged |
| Classification → Spam / Promotion / Completed Automatically | `ignored` | Decrements |
| Ignore * | `ignored` | Decrements |

### Cache / refresh

| Layer | Behavior after Apply |
|-------|----------------------|
| Widget cache | `forgetDashboardWidgetCache()` called — invalidated |
| Learning Center list | Uncached query on `status IN (needs_review, failed)` |
| Dashboard tile | Recomputes from same status set (TTL ≤ 60s, and cache forgotten) |
| DB | Source of truth — still `needs_review` for Docs/Assign |

**No divergence** between Dashboard / Learning Center / DB at investigation time.

### Exact service responsible

| Role | File |
|------|------|
| **Apply mutations** | `app/Services/IncomingEmail/IncomingEmailLearningActionService.php` |
| Rule persistence | `app/Services/IncomingEmail/IncomingEmailLearningRulesService.php` |
| Needs Human count | `app/Services/IncomingEmail/IncomingEmailIntakeCounterService.php` (`needsHumanCount` / `buildDashboardWidget`) |
| Operator classification enum (Docs not ignored) | `app/Enums/IncomingEmailOperatorClassification.php` |
| HTTP entry | Learning apply route → controller calling the action service |

### Issue 1 root cause

**Not a failed Apply and not a stale cache.**

Operators taught **Docs** and **Assign** on the three backlog rows. Those actions intentionally keep `status = needs_review`, so Needs Human remains **3**. The toast and rule save are correct; the count not moving is consistent with current business rules for those actions.

If the product intent is “Docs should leave Needs Human” or “Assign should move the row out of the queue,” that is a **product change** — not a production defect in invalidation.

### Recommended fix (do not implement yet)

Choose product intent, then implement one path:

1. **If Docs should leave Needs Human** (likely UX expectation): add `Docs` to `$shouldIgnore` (or set a dedicated status/queue), and document where Docs rows live (new queue vs Completed Automatically).  
2. **If Docs is label-only (current spec)**: keep code; fix UX copy / toast (“Labeled as Docs — still in Needs Human until Ignore or Move”). Optionally filter/badge Docs inside Needs Human.  
3. **If Assign should clear Needs Human**: define destination (owner queue, auto-create SC, or ignore) — Phase 1 deferred “Promote taught route → Service Case”; Assign alone was never a status transition.  
4. Do **not** “fix” by forcing cache clears — already correct.

---

## Issue 2 — Fresh emails not arriving

### Production evidence (2026-08-06 10:06 IST)

| Check | Result |
|-------|--------|
| `inbound_email.enabled` / `gmail.enabled` | **true / true** |
| Messages created last **2h** | **22** (17 `linked`, 5 `ignored`, **0** `needs_review`) |
| Messages created last **12h** | **38** (19 linked, 19 ignored) |
| Latest `created_at` | **2026-08-06 10:06:08** (seconds before probe) |
| Latest `received_at` | **2026-08-06 10:04:51** |
| Max message id | **179235** |
| `mail@radiumbox.com` `last_synced_at` | **2026-08-06 10:06:09** |
| `mail@` `history_id` / `profile_history_id` | **115905714** (matched; cursor advancing) |
| Last sync | processed **3**, cursor advances **4**, oauth **ok**, failures **0** |
| Sync log | Recent pulls (29, 22, 15, 11, …) — alive |
| Outbox pending/processing | **0** |
| Outbox processor log | Processing events |
| `jobs` / `failed_jobs` | 1 / 0 |

`support@radiumbox.com` sync-state row is **stale** (`last_synced_at` 2026-07-19) — mailbox appears unused for current intake; **active path is `mail@radiumbox.com`**.

### Latest messages (all auto-handled)

Recent ids `179231`–`179235` are all **`linked`** (`existing_customer` / `refund`) within the last few minutes — not stuck, not missing.

### Issue 2 root cause

**Intake has not stopped.** Gmail sync, scheduler-driven sync for `mail@`, and processing are healthy. New rows enter the DB continuously.

Operators do not see “fresh” mail in **Needs Human** because new messages are **auto-routed** to `linked` or `ignored` — same pattern as the earlier counter investigation. The Needs Human backlog remains the three Aug-3 rows until dispositioned with a status-changing action (Ignore / Move To Promo|Spam|Auto).

### Recommended fix (do not implement yet)

1. **No intake recovery required** for current data.  
2. Optionally alert if `mail@` `last_synced_at` lags &gt; N minutes (ops signal).  
3. Optionally clean/disable stale `support@` sync-state row to avoid confusion.  
4. Product: clarify Learning Center empty growth vs Dashboard “3” = untouched backlog, not frozen ingest.

---

## Files involved

| File | Relevance |
|------|-----------|
| `app/Services/IncomingEmail/IncomingEmailLearningActionService.php` | Apply status vs classification rules |
| `app/Enums/IncomingEmailOperatorClassification.php` | Docs / Move To mappings |
| `app/Services/IncomingEmail/IncomingEmailIntakeCounterService.php` | Needs Human / dashboard counts |
| `app/Services/IncomingEmail/IncomingEmailLearningRulesService.php` | Persistent rules on Apply |
| `docs/ira-learning-center-ux-redesign.md` | Docs “does not auto-ignore” |
| `docs/ira-learning-center-phase1.md` | Assign / classification status expectations |
| Gmail sync / outbox (scheduler) | Issue 2 health — running |

---

## Cross-check with prior investigation

[`docs/email-intake-dashboard-counter-investigation.md`](./email-intake-dashboard-counter-investigation.md) concluded the tile stuck at 3 was a true backlog. That still holds: Apply of Docs/Assign does not shrink that backlog; ingest continues to route around it.
