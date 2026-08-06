# IRA Learning Center UX Redesign

**Date:** 2026-08-06  
**Type:** Presentation-only  
**Backend:** unchanged Learning Rules / routing / processing  
**Canvas:** none  
**Related:** [docs/ira-learning-center-phase1.md](./ira-learning-center-phase1.md)

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

( Needs Human 12 )  ( Promotions 26 )  ( Spam 8 )  ( Auto Processed 43 )

Needs Human                          12 shown
┌──────────────────────────────────────────────────────────────────────────┐
│ ☐  Sender │ Subject                    │ IRA Suggestion │ Conf │ Owner │ Rec │ ⋯ │
│ ☐  Buyer  │ Need a quote · Looking…    │ Possible sales │ Med  │ —     │ …  │ ⋯ │
│ ☐  ACME   │ Invoice query · Please…    │ Support enquiry│ High │ Ravi  │ …  │ ⋯ │
└──────────────────────────────────────────────────────────────────────────┘
```

### Sticky teach toolbar

```
┌─ sticky ─────────────────────────────────────────────────────────────┐
│ ☐ Select all  2   Assign ▾   Choose user ▾   Same sender ▾   [Apply] │
└──────────────────────────────────────────────────────────────────────┘
```

### Expanded row (read-only)

```
│ ☐ Buyer │ Need a quote · Looking… │ Possible sales │ Med │ — │ … │ ⋯ │
│   Full preview          Existing customer     Service Case          │
│   Looking for pricing…  Unknown Customer      No service case       │
│   Matched Learning Rule  Previous confirmations                     │
│   None                   No prior confirmation                      │
│   Explainability: Why / examples / matched sender / rule confidence │
```

---

## Workflow

1. Select one or more rows (or use row `⋯` → Assign / Move / Ignore / Mark Important).  
2. Sticky toolbar appears.  
3. Choose action value + Learning Scope.  
4. Press **Apply** once.  
5. Existing `/admin/incoming-emails/learning` endpoint persists the decision / Learning Rule.

### Move To

UI action `Move To` maps to existing `classification` API values:

| Move To | Classification value (API) |
|---------|----------------------------|
| Promotions | `promotion` |
| Spam | `spam` |
| Auto Processed | `automatic` |

Learning Scope still applies (teach IRA on move).

---

## Classification UX refinements

### Docs

Operator-facing classification option for business-document mail (invoice, PO, quotation, statement, challan, credit/debit note, certificates, similar).

- Dropdown value: `docs`
- Stored classification: `docs` (string column; no schema migration)
- Does **not** auto-ignore (unlike Promotion / Spam / Auto Processed)
- No document intelligence in this phase — teach/label only

### Auto Processed

Every operator-facing “Automatic” label is **Auto Processed**.

| Surface | Internal value | Label |
|---------|----------------|-------|
| Classification dropdown | `automatic` | Auto Processed |
| Move To | `automatic` | Auto Processed |
| Queue tab | `automatic` | Auto Processed |
| Dashboard KPI hover ignored row | `automatic` | Auto Processed |

APIs, enums case names, and routing keys stay `automatic`.

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
| `resources/views/admin/incoming-emails/partials/learning-center.blade.php` | Page chrome + queue tabs + list |
| `resources/views/admin/incoming-emails/partials/learning-toolbar.blade.php` | Sticky teach toolbar |
| `resources/views/admin/incoming-emails/partials/learning-row.blade.php` | Compact row |
| `resources/js/ira-learning-center.js` | Selection / expand / Move To mapping |
| `resources/css/app.css` | Compact row / toolbar styles |
| `app/Services/IncomingEmail/IncomingEmailLearningCenterPresenter.php` | Row/expand display fields, confidence bands, Gmail/C360 links |
| `app/Http/Controllers/IncomingEmailAdminController.php` | All queues use Learning Center view data; `return_queue` |
| `app/Services/IncomingEmail/IncomingEmailLearningActionService.php` | Allow Ignored status so all queues can teach (same route/params) |
| `tests/Feature/IncomingEmail/IncomingEmailLearningCenterPhase1Test.php` | Asserts compact rows |
| `tests/Feature/IncomingEmail/IncomingEmailIntakeDashboardCountersTest.php` | Asserts Learning Center on spam queue |
| `docs/ira-learning-center-ux-redesign.md` | This document |

### Intentionally untouched

Learning Rules engine, smart routing, email processor, explainability matching logic, DB schema, route paths, request action vocabulary (`assign` / `classification` / `importance` / `ignore`).

---

## Unknown Customer

Still never exposes `unknown_customer`. UI shows **Unknown Customer** (or order customer name/email when known). Expand uses the same operator-facing label.
