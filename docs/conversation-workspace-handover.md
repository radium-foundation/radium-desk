# Customer Conversation Workspace — Post-Implementation Handover

**Status:** Phase 1 shipped (feature-flagged, default OFF)  
**Commit:** `075eae00ea51e78ba3b05e4c0f1b6873400cce85`  
**Date:** 2026-07-30  
**Audience:** Engineers extending or operating first-contact enquiry call UX inside Customer360

---

## 1. Feature overview

### What it is

**Customer Conversation Workspace** is a guided, conversation-first top section inside the existing Customer360 drawer. It appears only for **live first-time enquiry calls** when no product order is linked yet.

It is **not**:

- An IRA rewrite
- A Customer360 rewrite
- A new page or SPA
- A support / repair / refund / dispatch redesign

### Product goal

Help the agent continue a natural phone conversation by answering one question at a time:

> **What should I ask the customer next?**

Agents should never face a large form during a live enquiry call.

### When it appears

All of the following must be true:

1. `conversation_workspace.enabled` is ON  
2. Customer360 is opened with live-call context (`?cw=1`, typically from answered auto-open)  
3. The case is an **inquiry order** (`INQ-…`)  
4. The incident is **not** already linked to a product order (`inquiry_origin_order_id` is null)

### Mandatory vs optional capture

| During live call | Fields |
|------------------|--------|
| **Mandatory** | Customer Name, Customer Need |
| **Optional / later** | Conditional follow-ups, Email, WhatsApp, Agent Notes, Disposition, Next Action, More Details (city/brand/model/source) |

### UX shape

```
[Compact call header]  📞 phone · New Customer · First Contact · Agent · timer · Link Order
[Tiny IRA tip]
[One active guided question]
[Checklist  N / M  — collapsed]
[More Details — collapsed]
────────────────────────────────
Existing tabs: Overview | Timeline | IRA AI
```

---

## 2. Architecture summary

### Design principle

**Conditional top stack only.** Same drawer shell, same tabs, same workspace modals. When mode is inactive, Customer360 is unchanged (ops header, commercial state, quick actions).

```
Bonvoice answered call
  → (optional) bootstrap INQ for unknown caller
  → broadcast interaction { conversation_workspace, incident_id, call_id }
  → incoming-call-interaction.js → customer360:open
  → drawer fetch …/customer-360?cw=1&call_id=…
  → Customer360Service::drawerData($incident, $context)
  → ConversationWorkspaceModeResolver
  → if active: replace top chrome with conversation-workspace partial
  → initConversationWorkspace() binds progressive UI + PATCH saves
```

### Stack

| Layer | Technology |
|-------|------------|
| Shell | Existing Customer360 Blade drawer |
| Mode gate | PHP resolver + query flag `cw` |
| Guided questions | `CallCaptureQuestionResolver` + session view-model |
| Persistence | `conversation_workspace_sessions` + light order field mirror |
| Client | Vanilla JS module (`conversation-workspace.js`), no new Vite entry |
| Telephony | Bonvoice only |

### Naming note

Product name: **Conversation Workspace**  
Historical/technical name kept for the extensible question engine: **`CallCaptureQuestionResolver`** (do not rename casually — UI contract depends on `ConversationQuestion`).

---

## 3. Data flow

### A. Unknown caller answered (bootstrap path)

```
Bonvoice ANSWERED
  → BonvoiceLiveCallAssistService::maybeBroadcastAnsweredAutoOpen
  → if UnknownCaller + flags ON:
        ConversationWorkspaceBootstrapService::ensureIncidentForUnknownAnsweredCall
          1. Reuse open INQ for phone, else CustomerIntakeService::createNewContact
          2. Attach alert.incident_id / order_id
          3. Create ConversationWorkspaceSession
          4. Link call (IncidentBonvoiceCallLink, answered)
  → interaction.conversation_workspace = true
  → NotificationCreated (auto-open)
  → JS opens Customer360 with conversationWorkspace + callId
```

**Default (flags OFF):** unknown callers still **skip** auto-open (legacy behavior preserved).

### B. Known inquiry customer answered

If the matched order is already an unlinked inquiry, `shouldOpenConversationWorkspace()` returns true and auto-open includes `conversation_workspace: true`.

### C. Drawer render

```
GET /dashboard/service-cases/{incident}/customer-360?cw=1&call_id=…
  → Customer360Controller::show
  → drawerData($incident, [
        'live_incoming_call' => $request->boolean('cw'),
        'call_id' => …,
     ])
  → ModeResolver::isActive
  → SessionService::firstOrCreateForIncident + viewModel
  → Presenter → $conversationWorkspace
  → drawer-content branches on $conversationWorkspace['active']
```

### D. Progressive save

```
Agent answers one prompt
  → PATCH /dashboard/service-cases/{incident}/conversation-workspace
  → UpdateConversationWorkspaceRequest
  → SessionService::update (session + optional order.customer_name / customer_email)
  → JSON { workspace: viewModel }  // next question, checklist, progress
  → JS re-renders only the guide/checklist (no full drawer reload)
```

### E. Exit paths

| Event | Result |
|-------|--------|
| Link Existing Order succeeds | Existing link-order flow; after refresh, mode inactive (`inquiry_origin_order_id` set) |
| Flags turned off | Mode never activates; standard C360 chrome |
| Open C360 without `cw=1` | Mode inactive even for INQ (live-call gated) |

---

## 4. Feature flags

Config: `config/conversation_workspace.php`  
Env (see `.env.example`):

| Env var | Config key | Default | Purpose |
|---------|------------|---------|---------|
| `CONVERSATION_WORKSPACE_ENABLED` | `conversation_workspace.enabled` | `false` | Master switch for UI mode + bootstrap eligibility |
| `CONVERSATION_WORKSPACE_AUTO_CREATE_INQUIRY` | `conversation_workspace.auto_create_inquiry_on_answer` | `false` | Create/reuse INQ on unknown answered calls |

**Also required for auto-open:**

| Env var | Purpose |
|---------|---------|
| `BONVOICE_AUTO_OPEN_CUSTOMER360` | Existing Bonvoice answered → Customer360 open |

### Recommended enable order

1. Deploy with both Conversation Workspace flags **false**  
2. Enable `CONVERSATION_WORKSPACE_ENABLED` in a staging/soak environment  
3. Enable `CONVERSATION_WORKSPACE_AUTO_CREATE_INQUIRY` after confirming intake/assignment side effects  
4. Ensure `BONVOICE_AUTO_OPEN_CUSTOMER360=true` for the answered open path  

### Labels (config-driven)

Disposition and Next Action labels live in config (`dispositions`, `next_actions`). Enums read labels via config so copy can change without code edits.

---

## 5. Database changes

### Migration

`database/migrations/2026_07_30_130000_create_conversation_workspace_sessions_table.php`

### Table: `conversation_workspace_sessions`

| Column | Notes |
|--------|--------|
| `incident_id` | Unique FK → incidents (cascade delete) |
| `call_id` | Optional Bonvoice call id |
| `customer_name`, `customer_need` | Core capture |
| `email`, `whatsapp_same_number`, `whatsapp_number` | Optional contact |
| `brand`, `model`, `city`, `source`, `order_id_hint` | Conditional / more-details |
| `agent_notes` | Free-text notes (future IRA memory seed) |
| `disposition`, `next_action` | String-backed enums |
| `current_step`, `completed_fields`, `skipped_fields` | Progressive UX state |
| `status`, `completed_at` | `in_progress` / `completed` |
| `created_by`, `updated_by` | Users |

### Side effects on `orders`

For inquiry orders, saving name/email also mirrors onto `orders.customer_name` / `orders.customer_email` so dashboard / link-order stay consistent.

No changes to `release.json` or commercial-state schema.

---

## 6. New services / classes and responsibilities

### Mode & presentation

| Class | Responsibility |
|-------|----------------|
| `ConversationWorkspaceModeResolver` | Hard gate: flag + inquiry + not linked + `live_incoming_call` |
| `ConversationWorkspacePresenter` | Compact header view-model (phone, agent, update URL, session) |
| `ConversationWorkspaceSessionService` | Create/update session, build view-model, decide **active question** sequence, mirror order fields |
| `ConversationQuestion` (Data) | Stable DTO for one prompt (`key`, `prompt`, `input_type`, options, skippable) |

### Questions

| Class | Responsibility |
|-------|----------------|
| `CallCaptureQuestionResolver` | Given need + captured fields → next **conditional** follow-up question (or null). Deterministic Phase 1; IRA-replaceable later |
| `ConversationQuestionKey` | Canonical field keys; `isMandatoryLive()` = name + need |

### Call bootstrap

| Class | Responsibility |
|-------|----------------|
| `ConversationWorkspaceBootstrapService` | Unknown answered → reuse/create INQ, session, call link; `shouldOpenConversationWorkspace(alert)` |

### HTTP

| Class | Responsibility |
|-------|----------------|
| `ConversationWorkspaceController@update` | PATCH persistence |
| `UpdateConversationWorkspaceRequest` | Validation + mapped attributes (incl. whatsapp_choice / skip_field) |

### Enums

| Enum | Purpose |
|------|---------|
| `ConversationDisposition` | Reporting / lead foundation |
| `ConversationNextAction` | Agent’s chosen follow-through |
| `ConversationQuestionKey` | Step identity |

### Model

| Class | Responsibility |
|-------|----------------|
| `ConversationWorkspaceSession` | Eloquent + `capturedPayload()` / `hasMandatoryLiveFields()` |
| `Incident::conversationWorkspaceSession()` | HasOne relation |

### Touched existing services

| Class | Change |
|-------|--------|
| `BonvoiceLiveCallAssistService` | Bootstrap unknown callers; pass `conversation_workspace` on interaction |
| `BonvoiceIncomingCallInteractionBuilder` | Adds `conversation_workspace` bool |
| `Customer360Service::drawerData($incident, $context = [])` | Optional context; attaches `conversationWorkspace` payload |
| `Customer360Controller::show` | Reads `cw` / `call_id`; `Request` optional so `showForOrder()` still works |

---

## 7. Files added / modified

### Added

```
config/conversation_workspace.php
app/Data/ConversationWorkspace/ConversationQuestion.php
app/Enums/ConversationDisposition.php
app/Enums/ConversationNextAction.php
app/Enums/ConversationQuestionKey.php
app/Models/ConversationWorkspaceSession.php
app/Services/CallCapture/CallCaptureQuestionResolver.php
app/Services/ConversationWorkspace/ConversationWorkspaceModeResolver.php
app/Services/ConversationWorkspace/ConversationWorkspaceSessionService.php
app/Services/ConversationWorkspace/ConversationWorkspaceBootstrapService.php
app/Support/ConversationWorkspace/ConversationWorkspacePresenter.php
app/Http/Controllers/ConversationWorkspaceController.php
app/Http/Requests/UpdateConversationWorkspaceRequest.php
database/migrations/2026_07_30_130000_create_conversation_workspace_sessions_table.php
resources/views/customer-360/partials/conversation-workspace.blade.php
resources/js/conversation-workspace.js
tests/Unit/CallCapture/CallCaptureQuestionResolverTest.php
tests/Unit/ConversationWorkspace/ConversationWorkspaceModeResolverTest.php
tests/Unit/ConversationWorkspace/ConversationWorkspaceBootstrapServiceTest.php
tests/Feature/ConversationWorkspace/ConversationWorkspaceUpdateTest.php
tests/Feature/ConversationWorkspace/ConversationWorkspaceFlagOffRegressionTest.php
```

### Modified

```
.env.example
app/Models/Incident.php
app/Services/Customer360Service.php
app/Http/Controllers/Customer360Controller.php
app/Services/Bonvoice/BonvoiceLiveCallAssistService.php
app/Support/Bonvoice/BonvoiceIncomingCallInteractionBuilder.php
resources/views/customer-360/drawer-content.blade.php
resources/js/customer-360-drawer.js
resources/js/incoming-call-interaction.js
resources/css/app.css
routes/web.php
tests/Feature/BonvoiceLiveCallAssistTest.php
```

### Route

```
PATCH dashboard/service-cases/{incident}/conversation-workspace
  → dashboard.service-cases.conversation-workspace.update
```

### Client init

`customer-360-drawer.js` imports `initConversationWorkspace` and binds it in `bindCockpitChrome()` when `[data-conversation-workspace]` is present. No separate Vite input entry.

---

## 8. Extension points

### A. IRA Question Resolver (primary extension)

**Contract today**

```php
CallCaptureQuestionResolver::nextQuestion(
    string $customerNeed,
    ?string $currentStep,
    array $captured = [],
): ?ConversationQuestion
```

**UI contract:** return `ConversationQuestion` (or null). SessionService owns the fixed sequence around it:

1. Name (mandatory)  
2. Need (mandatory)  
3. **Resolver follow-ups** (0..N)  
4. Email → WhatsApp → Agent Notes → Disposition → Next Action  

**To plug IRA later:** replace/wrap `CallCaptureQuestionResolver` internals (or bind an interface implementation). Do **not** change Blade/JS prompt rendering if the DTO shape stays stable.

### B. Agent Notes → IRA Conversation Summary

- Stored as `agent_notes` (free text, no formatting rules)  
- Phase 1 does **not** auto-generate a summary  
- Future: IRA reads notes (+ captured fields) → durable Conversation Summary / memory; keep Agent Notes as human input

### C. Disposition

- Enum + config labels  
- Foundation for reporting, lead tracking, incentives  
- Extend by adding enum case + config label; avoid hard-coding labels in Blade

### D. Next Action

- Enum + config labels  
- `waiting_customer` is **stored only** in Phase 1 — not wired to `IncidentWaitingStateService` yet  
- Future: map selected actions to real domain side effects

### E. More Details

- City / Brand / Model / Source fields in session; collapsed UI  
- Safe place for low-priority attributes without growing the live guide

### F. Mode eligibility

Extend `ConversationWorkspaceModeResolver` carefully. Today deliberately requires **live incoming call context** (`cw=1`). Reopening an incomplete session from the dashboard without a live call does **not** enter the workspace.

---

## 9. Known limitations

1. **Flags default OFF** — production must opt in explicitly.  
2. **Live-call gated** — incomplete captures are not guided when opening C360 from the grid without `cw=1`.  
3. **Deterministic questions only** — keyword-style need matching inside resolver; not IRA.  
4. **Overview tab unchanged** — can still show heavy enquiry cards under the workspace top stack.  
5. **Tabs unchanged** — Overview / Timeline / IRA AI; no WhatsApp/Email tabs.  
6. **Next Action side effects** — enum persistence only (esp. Waiting Customer).  
7. **Call timer** — client-side from drawer open time, not true telephony answer timestamp.  
8. **Bootstrap assigns** answered agent when creating INQ (`assignOnCreate: false` then force-assign) — review against assignment policy if spam/unknown volume is high.  
9. **Dedupe** — reuses latest open INQ by exact `customer_phone`; normalized phone variants depend on intake/matcher behavior.  
10. **Email quick action** still stubbed globally in C360 (pre-existing).

---

## 10. Future roadmap

### Phase 2 (suggested)

- IRA-backed `CallCaptureQuestionResolver` (same DTO)  
- Generate Conversation Summary from Agent Notes + capture  
- Persist summary into IRA memory / timeline event  
- Wire `waiting_customer` / `follow_up` / `call_tomorrow` to waiting-state or tasks  
- Soft reopen: incomplete session without live call (read-only or light edit)  
- Declutter Overview when workspace active  

### Phase 3 (suggested)

- Disposition-based dashboards, incentives, conversion funnel  
- WhatsApp / Email surfaces as tabs or unified conversation (product decision)  
- Auto-draft quotes/coupons from Next Action  
- Stronger unknown-caller spam controls / rate limits  
- True call-duration from Bonvoice event timestamps  

### Explicit non-goals (unless product revisits)

- Rewriting IRA Overview intelligence pipeline  
- Redesigning support/repair/refund/dispatch Customer360 variants  

---

## 11. Test coverage

| Test | What it proves |
|------|----------------|
| `CallCaptureQuestionResolverTest` | Printer/laptop/order follow-ups; skip when answered; blank need |
| `ConversationWorkspaceModeResolverTest` | Flag / live-call / non-INQ / linked inquiry gates |
| `ConversationWorkspaceBootstrapServiceTest` | Unknown caller → INQ + session |
| `BonvoiceLiveCallAssistTest::…bootstraps_inquiry…` | End-to-end answered unknown + `conversation_workspace` interaction |
| `BonvoiceLiveCallAssistTest::…unknown…does_not_broadcast…` | Flags off preserve skip behavior |
| `ConversationWorkspaceUpdateTest` | PATCH name/need; order mirror; disabled flag 422 |
| `ConversationWorkspaceFlagOffRegressionTest` | Flags off → standard ops header + tabs; no workspace DOM |

### How to run

```bash
php artisan test --filter='ConversationWorkspace|CallCaptureQuestionResolver|test_unknown_caller_answered_bootstraps|test_unknown_customer_answered_call_does_not'
```

### Gap / debt in tests

- No browser/E2E for progressive JS step transitions  
- No explicit test that known INQ answered sets `conversation_workspace: true`  
- Full suite may show unrelated pre-existing RadiumBox HTML assertion failures (`bi-check-circle`, “Synchronization History” casing) — not introduced by this feature  

---

## 12. Rollback procedure

### Soft rollback (preferred)

1. Set env:
   - `CONVERSATION_WORKSPACE_ENABLED=false`
   - `CONVERSATION_WORKSPACE_AUTO_CREATE_INQUIRY=false`
2. Redeploy / reload config  
3. Confirm: unknown answered calls no longer auto-open; C360 shows classic chrome  

No migration rollback required for soft disable.

### Hard rollback

1. Soft-disable flags first  
2. Revert commit `075eae00ea51e78ba3b05e4c0f1b6873400cce85` (or deploy prior SHA)  
3. Optionally roll back migration:

```bash
php artisan migrate:rollback --path=database/migrations/2026_07_30_130000_create_conversation_workspace_sessions_table.php
```

Only drop the table if no reporting depends on captured sessions.

### What remains after soft rollback

- Code remains deployed but inert  
- Any INQ cases already created by bootstrap remain (normal enquiry cases)  
- Session rows remain until table dropped  

---

## Technical debt introduced

| Debt | Risk | Recommendation |
|------|------|----------------|
| Product name vs `CallCapture*` namespace | Confusion for newcomers | Keep resolver name for now; document prominently; introduce `QuestionResolver` interface in Phase 2 |
| Mode requires `cw` query param | Easy to “lose” workspace on refresh without param | Persist live-call hint on session or short-lived cache keyed by incident+agent |
| Client timer ≠ telephony answer time | Inaccurate talk time | Pass `answered_at` from Bonvoice event in open payload |
| Overview still heavy under workspace | Scroll / cognitive load | Gate overview cards when `conversationWorkspace.active` |
| Next Action enums without side effects | Agents think action “ran” | Either label as “Intent” or wire domain handlers in Phase 2 |
| Bootstrap creates cases on answer | Duplicate/spam risk if phones rematch poorly | Metrics + admin cleanup; tighten phone normalization / open-INQ reuse |
| `show(Incident, ?Request $request = null)` | Slightly unusual controller signature | Prefer always injecting Request and passing `request()` from `showForOrder` |
| Progressive UI re-renders via `innerHTML` | Loses focus quirks; XSS care if prompts become user/IRA HTML | Keep prompts server-trusted; consider targeted DOM updates |
| Disposition/next_action string columns | No DB check constraint | Acceptable; validate via FormRequest + enum casts |
| No Vite entry for CW JS | Bundled via dashboard drawer import — fine | Keep it that way unless CW is needed off-dashboard |

---

## Recommendations for future developers

1. **Always verify flags-off first** before changing C360 chrome — `ConversationWorkspaceFlagOffRegressionTest` is the safety net.  
2. **Do not put IRA Overview logic inside the tip card** — tip is static copy; intelligence belongs in resolver/summary later.  
3. **Extend questions via `ConversationQuestion` + resolver**, not new Blade forms.  
4. **Preserve performance:** no full drawer reload per answer; avoid N+1 in `drawerData` for mode checks.  
5. **Unknown-caller bootstrap** sits on the answered latency path — measure with `docs/ivr-incoming-popup-latency.md` before adding work.  
6. **Link Order** must keep using existing workspace fragment (`link-order`); do not fork a second linker.  
7. When adding reporting, treat `disposition` + `next_action` + `agent_notes` as the analytics source of truth on `conversation_workspace_sessions`.  
8. Unrelated untracked scheduler files may exist in local trees — do not mix them into Conversation Workspace PRs.

---

## Quick start (local)

```bash
# .env
CONVERSATION_WORKSPACE_ENABLED=true
CONVERSATION_WORKSPACE_AUTO_CREATE_INQUIRY=true
BONVOICE_AUTO_OPEN_CUSTOMER360=true

php artisan migrate
php artisan test --filter='ConversationWorkspace|CallCaptureQuestionResolver'
```

Manual check:

1. Simulate/produce unknown inbound → answer  
2. Confirm Customer360 opens with `[data-conversation-workspace]`  
3. Capture Name + Need only; skip the rest  
4. Disable flags; reopen same INQ without `cw` → classic C360  

---

## Related docs

- `docs/ivr-incoming-popup-latency.md` — Bonvoice incoming path latency  
- `docs/ira-v2-intelligence-pipeline.md` — IRA Overview (separate; do not conflate)  
- `docs/customer360-workspace-modal-design-system.md` — workspace modal primitives (Link Order, etc.)  
- `docs/dashboard-architecture.md` — drawer host / dashboard wiring  

---

*End of handover. For questions, start with `ConversationWorkspaceModeResolver` (when UI shows) and `CallCaptureQuestionResolver` (what to ask next).*
