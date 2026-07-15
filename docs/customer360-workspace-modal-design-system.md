# Customer360 Workspace Modal Design System

Visual and layout standard for workspace modals opened from Customer360 (and other surfaces using `#workspaceModal` / `[data-workspace-modal-host]`).

**Scope:** UI/UX only. Business logic, validation, routes, and services are unchanged by this standard.

## Design philosophy

Workspace modals should feel like **Linear**, **Stripe**, **GitHub**, or **Notion** — fast, minimal, operator-first. They are **decision dialogs**, not web pages inside a popup.

Principles:

- Typography over containers
- Whitespace over borders
- Information first, actions second
- No nested cards, shadows, or decorative chrome
- The operator should grasp the decision in ~3 seconds

## Modal anatomy

Every workspace modal follows this vertical flow:

```
Title
  ↓
Compact Context (optional)
  ↓
Hero KPI (when a key number or status drives the action)
  ↓
Compact Form
  ↓
Sticky Footer
```

### 1. Title

- Use `x-c360.dialog-header` with icon + title only
- **No subtitle** — no explanatory sentences under the title
- Compact padding inside `[data-workspace-modal-host]`

### 2. Compact context

- Use `x-c360.workspace-context-strip` with `workspace-context-strip--minimal`
- One horizontal row: **who · order · case**
- Icons only where they improve scanning (typically customer only)
- No uppercase labels, no cards, no repeated words (“Customer”, “Order”)

```blade
<x-c360.workspace-context-strip class="workspace-context-strip--minimal">
    <x-c360.workspace-context-item icon="👤">{{ $customerName }}</x-c360.workspace-context-item>
    <x-c360.workspace-context-item>{{ $orderId }}</x-c360.workspace-context-item>
    <x-c360.workspace-context-item>{{ $caseReference }}</x-c360.workspace-context-item>
</x-c360.workspace-context-strip>
```

### 3. Hero KPI (when applicable)

Use when one metric answers the operator’s primary question (e.g. refund amount, balance due).

- **Number is the hero** — large, bold, first
- **Caption is secondary** — muted line below (e.g. “Maximum Refundable”)
- Supporting stats on one line with `·` separators
- Payment/status meta on the next line via `x-c360.workspace-kpi-meta` + `x-c360.status-chip`

```blade
<x-c360.workspace-hero-kpi :amount="'₹'.number_format($amount, 2)" caption="Maximum Refundable">
    <x-slot:secondary>
        <x-c360.workspace-kpi-secondary>
            <x-c360.workspace-kpi-secondary-item label="Paid">₹…</x-c360.workspace-kpi-secondary-item>
            <x-c360.workspace-kpi-secondary-item label="Refunded">₹…</x-c360.workspace-kpi-secondary-item>
        </x-c360.workspace-kpi-secondary>
    </x-slot:secondary>
    <x-slot:meta>...</x-slot:meta>
</x-c360.workspace-hero-kpi>
```

**Do not** add section headings like “Refund Information” or “Summary” above the KPI.

### 4. Compact form

- Wrap fields in `x-c360.workspace-dialog-stack` with `workspace-dialog-stack--form`
- **No section titles** between KPI and fields — flow continues naturally
- Use `workspace-form-row workspace-form-row--2` for paired fields (e.g. Method + Amount)
- Labels: small, uppercase, muted (`workspace-form-label`)
- Long hints → `x-c360.workspace-field-hint` (ⓘ tooltip), not paragraphs
- Reason textareas: **3 rows**, `workspace-form-textarea--compact`

### 5. Inline notifications

- Label: **Notify** (not “Notify Customer”)
- Inline checkboxes: Email, WhatsApp
- Optional ⓘ for send timing — no separate card or container

### 6. Sticky footer

- `x-c360.modal-footer` with `workspace-dialog-footer`
- GitHub-style hierarchy: **Cancel** (secondary/outline) → **Submit** (compact primary)
- `btn-sm`, no oversized buttons, no gradient lift or shadow on workspace footers

## Shared components

| Component | Purpose |
|-----------|---------|
| `x-c360.dialog-header` | Title bar |
| `x-c360.workspace-context-strip` | Compact context row |
| `x-c360.workspace-context-item` | Context value (`icon` optional) |
| `x-c360.workspace-hero-kpi` | Hero number + caption + slots |
| `x-c360.workspace-kpi-secondary` | Inline supporting metrics |
| `x-c360.workspace-kpi-secondary-item` | Single metric in secondary row |
| `x-c360.workspace-kpi-meta` | Status chip + payment meta (`·` separated) |
| `x-c360.workspace-dialog-stack` | Flat vertical grouping (no cards) |
| `x-c360.workspace-field-hint` | Tooltip info icon |
| `x-c360.status-chip` | Payment/status badges |
| `x-c360.modal-footer` | Sticky actions |

## CSS namespace

Shared styles live in `resources/css/app.css` under:

- `[data-workspace-modal-host]` — shell width (~960px), header/body/footer compaction
- `.workspace-*` — reusable layout primitives

Avoid action-specific CSS unless unavoidable. Prefer extending shared classes.

## Modal shell

- Host: `resources/views/workspace/partials/workspace-modal-host.blade.php`
- JS shell: `resources/js/workspace/dialog-shell.js` (dirty tracking, discard confirm)
- Init: `initWorkspaceDialogShell()` on `afterOpen` in `resources/js/app.js`

## Actions using this standard

| Action | Hero KPI | Notes |
|--------|----------|-------|
| Refund | Yes — maximum refundable | Reference implementation |
| Link Order | No | Context + single field |
| Correct Customer | No | Sidebar context + form grid |
| Correct Serial | No | Same correction pattern |
| Correct Device Model | No | Same correction pattern |
| Communication | No | Compact selects + message |

Migrate existing dialogs to this pattern incrementally; do not fork per-action layouts.

## Responsiveness

- Target: **1920×1080 @ 100% zoom**, no vertical scroll for the common path
- Only validation errors or long user input should introduce scrolling
- Mobile: context stack, form rows collapse to single column (see `@media` rules in `app.css`)

## Reference implementation

`resources/views/customer-360/fragments/refund-request-form.blade.php`
