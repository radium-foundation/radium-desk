# P[04-08]-008 — Unified Communication Phase 1

**Date:** 2026-08-04  
**Status:** Implemented  
**Canvas:** [`p-04-08-008-unified-communication-phase1.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p-04-08-008-unified-communication-phase1.canvas.tsx)  
**Depends on:** Email Workspace Phase 1–2, existing Interakt WhatsApp pipeline

---

## Verdict

Unified Communication Phase 1 adds a **Communication** section on the Service Case (C360 Overview) that surfaces WhatsApp and Email with a shared visual language. Channels still open their **existing** interfaces — Email Workspace modal and WhatsApp/Interakt external conversation — without a new messaging module or inbox.

Success criteria: One Customer → One Service Case → One Owner → One Timeline → Multiple channels (WhatsApp + Email) with consistent chrome.

---

## Architecture summary

```
Service Case (single source of truth)
  ├── Communication section (channel cards)
  │     ├── WhatsApp → existing wa.me / Interakt deep link (+ channel panel header)
  │     └── Email → existing Email Workspace modal
  ├── Unified Timeline (existing sources, no duplicates)
  └── Shared channel header meta (customer, owner, last in, last out)
```

| Rule | How |
|------|-----|
| Reuse WhatsApp | Toolbar + Interakt aggregator / deep link; no in-app WhatsApp clone |
| Reuse Email | `service-case-email-workspace` + conversation API |
| Blade templates | Shared `x-c360.channel-meta-header` only |
| No new module / inbox | Section is a launcher + consistent chrome |
| Permissions | Unchanged (`email.reply` / assignee gate; WhatsApp remains external) |
| Timeline | Existing chronological writers only — no duplicate entries |

---

## What was implemented

1. **Communication section** — Overview cards for WhatsApp + Email opening existing UIs  
2. **Unified timeline** — Confirmed existing chronological sources; no duplicate writers  
3. **Consistent header** — Customer, owner, last inbound, last outbound on Email workspace + WhatsApp channel panel  
4. **Shared composer UX helpers** — Extracted to `c360-channel-ux.js` (send lock, toasts, scroll, highlight); Email reuses them  
5. **Shared permissions** — No new permissions  
6. **Component reuse** — Shared header Blade + CSS; WhatsApp panel JS; no large rewrites  
7. **Performance** — One presenter per drawer open; reuses phone-scoped queries; no new WebSockets / pollers  

---

## Files modified

| Path | Role |
|------|------|
| `app/Support/Customer360/Customer360CommunicationSectionPresenter.php` | Channel card + meta payload |
| `app/Services/Customer360Service.php` | Expose `communicationSection` |
| `app/Services/IncomingEmail/IncomingEmailConversationService.php` | Header meta + customer/owner labels |
| `resources/views/customer-360/partials/communication-section.blade.php` | Section UI |
| `resources/views/customer-360/partials/whatsapp-channel-panel.blade.php` | Lightweight WhatsApp panel |
| `resources/views/components/c360/channel-meta-header.blade.php` | Shared header |
| `resources/views/customer-360/partials/service-case-email-modal.blade.php` | Shared header slots |
| `resources/views/customer-360/drawer-content.blade.php` | Include section + WA panel |
| `resources/js/c360-channel-ux.js` | Shared UX helpers |
| `resources/js/service-case-email-workspace-helpers.js` | Re-export shared helpers |
| `resources/js/service-case-email-workspace.js` | Consistent header binding |
| `resources/js/service-case-whatsapp-panel.js` | Open existing WA/Interakt links |
| `resources/js/customer-360-drawer.js` | Init WhatsApp panel |
| `resources/css/app.css` | Shared channel styles |
| `tests/Feature/Customer360/UnifiedCommunicationPhase1Test.php` | Section + channel coverage |
| `tests/Feature/Customer360DrawerTest.php` | Allow full owner name in Communication |
| `tests/Feature/IncomingEmail/ServiceCaseEmailWorkspacePhase2Test.php` | customer/owner label assertions |

---

## Performance review

- Communication section built once in `drawerData` (no per-click query storm).  
- WhatsApp last in/out: two indexed `InteraktMessage` lookups by phone + direction when phone present.  
- Email card meta uses lightweight `headerMetaForIncident` (not full thread page).  
- Email Workspace keeps its existing 20s poller only while the email modal is open — no second poller for WhatsApp.  
- Timeline refresh unchanged — Email Workspace continues to use existing timeline refresh after send.  
- No WebSockets introduced for this work.

---

## Test results

| Suite | Result |
|-------|--------|
| `UnifiedCommunicationPhase1Test` | Passed |
| `ServiceCaseEmailWorkspacePhase1Test` | Passed |
| `ServiceCaseEmailWorkspacePhase2Test` | Passed |
| `OutgoingEmailReplyTest` | Passed |
| Combined filter (25 tests) | **25 passed** |
| Vitest `service-case-email-workspace-helpers` | 5 passed |
| Vitest `customer-360-drawer` URL absolute-vs-relative asserts | 3 pre-existing failures unrelated to this change |

Verified behaviours:

- Communication section renders WhatsApp + Email with shared meta fields  
- WhatsApp opens panel with wa.me / Interakt links (not an in-app composer)  
- Email opens existing workspace; thread API returns customer/owner labels  
- WhatsApp disabled when phone missing  
- Existing Email reply / unread / pagination paths unchanged  

---

## Rollback strategy

1. Remove Communication section include + WhatsApp panel + presenter wiring from `Customer360Service` / `drawer-content`.  
2. Revert shared header in Email modal to previous markup if needed.  
3. Keep Email Workspace and Interakt pipelines intact.  
4. No schema migrations — safe rollback of UI-only wiring.

---

## Out of scope

SMS, Calls, Attachments, Inbox, CRM messaging centre, Gmail clone, WhatsApp clone, AI Notes UI (future channel placeholder chips only).

---

## Success criteria

Radium Desk presents every customer interaction through one Service Case, one owner and one unified timeline while WhatsApp and Email coexist with consistent chrome.
