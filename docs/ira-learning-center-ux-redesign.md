# IRA Learning Center UX Redesign

**Date:** 2026-08-06 (UX Polish Sprint same day; Review panel redesign later same day)  
**Type:** Presentation + Review UX orchestration (backend Teaching / Disposition / Audit services unchanged)  
**Backend:** unchanged Learning Rules / routing / processing (except Spam → Needs Review when human works mail)  
**Canvas:** [learning-center-review-panel.canvas.tsx](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/learning-center-review-panel.canvas.tsx) · [completed-automatically-209.canvas.tsx](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/completed-automatically-209.canvas.tsx)  
**Related:** [docs/ira-learning-center-phase1.md](./ira-learning-center-phase1.md) · [docs/email-intake-disposition-workflow.md](./email-intake-disposition-workflow.md) · [docs/completed-automatically-209-investigation.md](./completed-automatically-209-investigation.md)

---

## UX Polish Sprint (pre–IRA Memory M3)

No AI / routing architecture changes.

### Row expansion

- Expand always shows **Subject** + **Preview** from the stored snippet (`data-subject` / `data-preview`).
- Preview clamps to **5 lines** with overflow ellipsis.
- Additional explainability JSON is best-effort; on parse failure show **Unable to load additional details.** + **Retry** (never blank the whole panel).
- Root cause of blank “Unable to load details”: Blade `{{ e(json) }}` double-escaped quotes (`&amp;quot;`), so `JSON.parse` failed. Encode once via `{{ $json }}`.

### Spam human-work recovery

If an operator Assigns / Teaches / Creates a case / Links a case from Spam:

1. Confirm: *“This email will be removed from Spam and returned to Needs Review.”*
2. Backend restores `status=needs_review`, clears spam ignore markers, then applies the action.
3. Human-owned mail never remains in Spam.

### Completed Automatically (operator label)

| Surface | Internal value | Operator label |
|---------|----------------|----------------|
| Queue tab | `automatic` | **Completed Automatically** |
| Disposition | `auto_processed` | **Completed Automatically** |
| Classification | `automatic` | **Completed Automatically** |

Completed Automatically rows hide Confidence + Suggested Owner; show **Handled By → IRA** and **Result → Completed Automatically** (or **Linked to SC#####** when a service case exists).

### Completed Automatically — operator breakdown (presentation only)

Internal groups under `?queue=automatic` (no routing / filter / ingest changes):

| Operator group | Internal key | Source |
|----------------|--------------|--------|
| System Notifications | `system_notifications` | `ignore_reason=known_system_email` |
| Auto Replies | `auto_replies` | `ignore_reason=auto_responder` |
| Own Outbound | `own_outbound` | `ignore_reason=own_outbound` or classification `own_outbound` |
| Bounces | `bounces` | `ignore_reason=bounce_or_delivery_subsystem` |
| Duplicate Notifications | `duplicate_notifications` | Same subject appears 2+ times in Completed Automatically |

UI: sub-chip strip under the Completed Automatically tab (`?sub=`). Rows show the group under Result. Expand shows **Automatic group**.

### Review Suggested (presentation only)

New queue tab `review_suggested` — **Review Suggested**.

- Includes Needs Human mail where IRA recorded `ira_confidence < 45`, or `status=failed`
- Does **not** remove those emails from Needs Human
- Does **not** change ingest, filter, classifier, or routing

### Completed Automatically investigation (production, read-only)

Population = ignored mail with Automatic queue reasons (`auto_responder`, `bounce_or_delivery_subsystem`, `known_system_email`, `own_outbound`) or `own_outbound` / `auto_processed`.

| Metric | Count |
|--------|------:|
| Total | **7,686** |
| Linked to orders | **0** |
| Linked to service cases | **0** |
| By ignore_reason: known_system_email | 6,275 |
| By ignore_reason: auto_responder | 1,360 |
| By ignore_reason: own_outbound | 44 |
| By ignore_reason: bounce_or_delivery_subsystem | 7 |
| Duplicate-subject rows (sum of subjects with count>1) | 4,953 |
| known_system subjects with help/issue/problem/complaint (keyword scan) | 209 |

**209 “suspicious” follow-up:** all keyword false positives (Amazon ASIN “customer complaints”, Flipkart/GeM/Google subjects containing “help”/“issue”). 170/209 from `donotreply@amazon.com`. After excluding noreply/system senders → **0** genuine misroutes. Full write-up: [docs/completed-automatically-209-investigation.md](./completed-automatically-209-investigation.md).

---

## Objective

Turn the IRA Learning Center into a compact, high-speed operator workspace for teaching IRA.

Not Gmail. Not CRM. Review → decide → Apply once.

---

## Before vs After

### Before

- Large vertical cards (~280–400px each)
- Four Apply button groups per card (Assign / Classification / Importance / Ignore)
- Repeated scope dropdowns on every card
- Explainability always in DOM via `<details>`
- Only Needs Human used Learning Center UI
- Other queues were a plain table
- Confidence shown as raw `%`

```
┌──────────────────────────────────────────────┐
│ ☐  Sender / Customer / Received              │
│    Subject                                   │
│    Preview preview preview…                  │
│    IRA Decision · Confidence · Assignee      │
│    Reason…                                   │
│    �…                  │
│    IRA Decision · Confidence · Assignee      │
│    Reason…                                   │
│    ▸ Why this suggestion?                    │
│    [Assign ▾][Scope ▾][Apply]                │
│    [Class  ▾][Scope ▾][Apply]                │
│    [Import ▾][Scope ▾][Apply]                │
│    [Ignore ▾][Scope ▾][Apply]                │
└──────────────────────────────────────────────┘
```

### After

- One compact row per email (~60px)
- Sticky toolbar appears after selection
- Single Apply button
- Row `⋯` menu for quick actions
- Expand-on-click (lazy) for read-only detail
- All queues share the Learning Center
- Confidence: High / Medium / Low (tooltip = exact %)

```
Sticky toolbar (after select)
[Select all 2] [Assign▾] [User▾] [Scope▾] [Apply]

☐ Sender     Subject · preview     Suggestion   Med   Owner   Received   ⋯
☐ Sender     Subject · preview     Suggestion   High  Owner   Received   ⋯
  └ expand (lazy): preview · explainability · customer · SC · rule · confirmations
```

---

## Screenshots (layout reference)

Live UI: `/admin/incoming-emails?queue=needs_human`  
(No Canvas; ASCII wireframes below match shipped layout.)

### Queue strip + compact list

```
IRA Learning Center
Review-and-teach workspace for inbound email — not a Gmail inbox.

( Needs Human 12 )  ( Review Suggested 3 )  ( Promotions 26 )  ( Spam 8 )  ( Completed Automatically 43 )
  └ when Completed Automatically active:
    ( All ) ( System Notifications ) ( Auto Replies ) ( Own Outbound ) ( Bounces ) ( Duplicate Notifications )

Needs Human                          12 shown
┌ Review panel (opens on row select) ──────────────────────────────────┐
│ Teach IRA (optional)     │ Disposition (required)                     │
│ Owner / Class / Import.  │ Action + conditional fields                │
│ Scope                    │                              [Close] [Save]│
└──────────────────────────────────────────────────────────────────────┘
┌──────────────────────────────────────────────────────────────────────────┐
│ ●  Sender │ Subject                    │ IRA Suggestion │ Conf │ Owner │ Rec │ ⋯ │
│ ○  Buyer  │ Need a quote · Looking…    │ Possible sales │ Med  │ —     │ …  │ ⋯ │
│ ○  ACME   │ Invoice query · Please…    │ Support enquiry│ High │ Ravi  │ …  │ ⋯ │
└──────────────────────────────────────────────────────────────────────────┘
```

### Unified review panel (one Save)

```
┌─ sticky review ──────────────────────────────────────────────────────┐
│ Need a quote · buyer@example.com                                     │
│ Teach: Owner ▾  Classification ▾  Importance ▾  Scope ▾              │
│ Dispose: Create Service Case ▾  Owner (optional) ▾        [Save]     │
└──────────────────────────────────────────────────────────────────────┘
```

---

## Workflow

1. Click one email row (or `⋯` → Review).  
2. Compact review panel opens with Teach + Disposition together.  
3. Change teach fields only if needed; choose disposition.  
4. Press **Save** once → `POST /admin/incoming-emails/review`  
   - Teach runs only when Owner / Classification / Importance differ from baselines  
   - Disposition always runs  
   - Existing audit events + IRA Memory writes unchanged  
5. Terminal disposition removes the email from Needs Human immediately.

Legacy teach-only / disposition-only endpoints remain available; the Learning Center UI no longer uses dual toolbars.

---

## Classification UX refinements

### Docs

Operator-facing classification option for business-document mail (invoice, PO, quotation, statement, challan, credit/debit note, certificates, similar).

- Dropdown value: `docs`
- Stored classification: `docs` (string column; no schema migration)
- Does **not** auto-ignore (unlike Promotion / Spam / Completed Automatically)
- No document intelligence in this phase — teach/label only

### Completed Automatically

Every operator-facing “Automatic” label is **Completed Automatically** (was “Auto Processed”).

| Surface | Internal value | Label |
|---------|----------------|-------|
| Classification dropdown | `automatic` | Completed Automatically |
| Disposition | `auto_processed` | Completed Automatically |
| Queue tab | `automatic` | Completed Automatically |
| Dashboard KPI hover ignored row | `automatic` | Completed Automatically |

APIs, enums case names, and routing keys stay `automatic` / `auto_processed`.

### Compact dropdowns

Learning Center selects / row menus tightened toward GitHub–Linear density:

- Font ~13px (`0.8125rem`)
- Control / menu row height ~34px
- Reduced padding and popup width (`max-width` / `min-width` ~9.5rem)
- Checkmark / flex alignment preserved on `.dropdown-item`

### Row action menu (no clipping)

The row `⋯` menu is ported to `document.body` with `position: fixed` so `.ira-lc-list { overflow: hidden }` cannot clip it.

- Opens below when space exists; flips above near the viewport bottom
- Clamped to viewport edges (no horizontal scroll)
- Outside click / ESC / scroll / resize close it
- Arrow keys move between items; compact shadow + short fade animation

### Row menu

| Item | Behavior |
|------|----------|
| Assign | Selects row, opens toolbar on Assign |
| Move | Selects row, opens toolbar on Move To |
| Ignore | Selects row, opens toolbar on Ignore |
| Mark Important | Selects row, Importance = High |
| Open Gmail | Deep link via RFC Message-ID / search |
| Open Customer360 | When linked incident exists |

---

## Confidence bands

| Band | Range | Tooltip |
|------|-------|---------|
| High | ≥ 75 | exact `%` |
| Medium | 45–74 | exact `%` |
| Low | < 45 | exact `%` |

---

## Performance impact

| Concern | Mitigation |
|---------|------------|
| DOM size | Removed 4 forms × N rows; one sticky form |
| Expand cost | Lazy: JSON on row, HTML rendered on first open, JSON attribute cleared |
| 1000+ rows | Row markup is ~1 compact grid; expand payload stays inert until opened |
| List query | Still capped at 100 (existing intake counter query); redesign does not add queries beyond incident eager-load for C360 links |

Expected: far fewer nodes per email vs Phase 1 cards; sticky toolbar cost is O(1).

---

## Files changed

| File | Change |
|------|--------|
| `resources/views/admin/incoming-emails/index.blade.php` | Thin page shell (`@include` only) |
| `resources/views/admin/incoming-emails/partials/learning-center.blade.php` | Page chrome + queue tabs + automatic sub-chips + list |
| `resources/views/admin/incoming-emails/partials/learning-toolbar.blade.php` | Sticky teach toolbar |
| `resources/views/admin/incoming-emails/partials/learning-row.blade.php` | Compact row + automatic group secondary |
| `resources/js/ira-learning-center.js` | Selection / expand / Move To mapping |
| `resources/css/app.css` | Compact row / toolbar / subcategory styles |
| `app/Enums/IncomingEmailAutomaticSubcategory.php` | Completed Automatically operator groups |
| `app/Enums/IncomingEmailIntakeQueue.php` | Review Suggested + Completed Automatically labels |
| `app/Services/IncomingEmail/IncomingEmailIntakeCounterService.php` | Subcategory breakdown + Review Suggested query |
| `app/Services/IncomingEmail/IncomingEmailLearningCenterPresenter.php` | Row/expand display fields, confidence bands, Gmail/C360 links |
| `app/Http/Controllers/IncomingEmailAdminController.php` | All queues use Learning Center view data; `return_queue`; `?sub=` |
| `app/Services/IncomingEmail/IncomingEmailLearningActionService.php` | Allow Ignored status so all queues can teach (same route/params) |
| `tests/Feature/IncomingEmail/IncomingEmailLearningCenterPhase1Test.php` | Asserts compact rows, subcategory filter, Review Suggested |
| `tests/Feature/IncomingEmail/IncomingEmailIntakeDashboardCountersTest.php` | Asserts Learning Center on spam queue |
| `docs/ira-learning-center-ux-redesign.md` | This document |

### Intentionally untouched

Learning Rules engine, smart routing, email processor, explainability matching logic, DB schema, route paths, request action vocabulary (`assign` / `classification` / `importance` / `ignore`).

---

## Unknown Customer

Still never exposes `unknown_customer`. UI shows **Unknown Customer** (or order customer name/email when known). Expand uses the same operator-facing label.
