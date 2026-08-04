# P[04-08]-006 — Phase 1: Service Case Email Workspace

**Date:** 2026-08-04  
**Status:** Implemented  
**Canvas:** [`p-04-08-006-service-case-email-workspace-phase1.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p-04-08-006-service-case-email-workspace-phase1.canvas.tsx)

---

## Verdict

The C360 Email toolbar placeholder is replaced with a minimal Service Case email conversation modal: chronological thread, Reply, subject, plain multiline editor, and Send. Email remains a communication channel on the Service Case — not a separate inbox.

---

## Architecture reuse

| Concern | Reuse |
|---------|--------|
| Inbound body | `IncomingEmailLiveContentService` + content route |
| Outbound send | `OutgoingEmailReplyService::send` (`template_key: blank`) |
| MIME / Blade | Existing MIME builder + Blade mail path |
| Permissions | `OutgoingEmailReplyGate` (`email.reply` OR Linked SC assignee) |
| Timeline / audit | `outgoing_email.sent` + existing timeline sources |
| Thread data | `IncomingEmailConversationService` + `GET …/email-thread` |

---

## UI

**Placement:** Email toolbar opens a focused conversation modal (WhatsApp-like overlay).

- Conversation thread (inbound / outbound bubbles)
- Reply button
- Subject field
- Plain multiline message editor
- Send button

After send: Gmail outbound pipeline → append bubble → refresh timeline → preserve audit.

---

## Permissions

| Actor | Reply |
|-------|-------|
| Admin / Ops / SuperAdmin | Allowed (`email.reply`) |
| Assigned SC owner | Allowed (gate exception) |
| Other / unassigned agents | Blocked |

---

## Out of scope

Attachments, drafts, Reply All, Forward, CC/BCC, rich text, Template Store, Inbox, Labels, separate email module, auto-create flag enablement.

---

## Files changed

| Path | Change |
|------|--------|
| `IncomingEmailConversationService.php` | Thread aggregation |
| `Customer360Controller.php` | `emailThread` JSON |
| `routes/web.php` | `…/email-thread` route |
| `quick-action-toolbar.blade.php` | Enable Email |
| `service-case-email-modal.blade.php` | New modal |
| `drawer-content.blade.php` | Include modal |
| `service-case-email-workspace.js` | Thread + composer |
| `customer-360-cockpit.js` | Wire Email open |
| `customer-360-drawer.js` | Init workspace + timeline refresh hook |
| `incoming-email-modal.js` | Export helpers; refresh after send |
| `app.css` | Minimal bubble styles |
| Feature tests | Thread + permissions + send |

---

## Tests

`php artisan test --filter='ServiceCaseEmailWorkspacePhase1Test|OutgoingEmailReplyTest'` → **17 passed**

| Case | Result |
|------|--------|
| Empty thread | ✅ |
| Inbound + outbound ordered | ✅ |
| Admin / SuperAdmin reply | ✅ |
| Assigned owner reply (no `email.reply`) | ✅ |
| Other / unassigned agent blocked | ✅ |
| Existing `OutgoingEmailReplyTest` | ✅ green |

---

## Rollback

Revert toolbar enablement, modal/JS, route, and conversation service. Reply gate and Gmail pipeline unchanged. No DB migration to unwind.
