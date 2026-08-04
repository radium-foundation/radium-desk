# P[04-08]-007 — Email Workspace Phase 2

**Date:** 2026-08-04  
**Status:** Implemented  
**Depends on:** [`docs/p-04-08-006-service-case-email-workspace-phase1.md`](p-04-08-006-service-case-email-workspace-phase1.md)  
**Canvas:** [`p-04-08-007-service-case-email-workspace-phase2.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p-04-08-007-service-case-email-workspace-phase2.canvas.tsx)

---

## Verdict

Phase 2 polishes the Phase 1 Service Case Email Workspace without changing architecture. Gmail in/out, Blade templates, ReplyGate, and timeline sources stay in place. Email remains a channel on the Service Case.

---

## Architecture summary (reuse)

| Layer | Reuse |
|-------|--------|
| Inbound | Existing ingest / live content |
| Outbound | `OutgoingEmailReplyService` + MIME + Gmail API |
| Permissions | `OutgoingEmailReplyGate` |
| Timeline | Existing sources; jump opens workspace |
| Polling | Drawer timeline poll pattern (no new WebSockets) |
| Toasts | `showAppToast` |
| Thread API | Extended `IncomingEmailConversationService` |
| Unread | Cache cursor per user+incident (no Gmail labels) |

---

## Phase 2 improvements

1. **Conversation UX** — auto-scroll latest; preserve scroll when loading older; spinner; empty state  
2. **Composer** — Send lock / duplicate guard; keep draft on failure; toast success/failure; refocus editor  
3. **Subject** — Prefill `Re: <latest>` without overwriting manual edits  
4. **Header timestamps** — Last customer email / last outgoing  
5. **Unread badge** — Toolbar indicator; clears when workspace opened  
6. **Live refresh** — Poll while workspace open; append; timeline refresh; sticky scroll  
7. **Timeline jump** — Open workspace, scroll, highlight  
8. **Performance** — Column selects, bounded page size, older/newer cursors, no N+1  

---

## Files modified

| Path | Change |
|------|--------|
| `IncomingEmailConversationService.php` | Pagination, timestamps, unread helpers |
| `IncomingEmailWorkspaceReadState.php` | Read cursor (cache) |
| `Customer360Controller.php` | Thread query params + mark-read |
| `routes/web.php` | mark-read route |
| `Customer360Service` / toolbar | Unread badge data |
| `service-case-email-modal.blade.php` | Header stats, skeleton |
| `service-case-email-workspace.js` | UX polish, poll, jump |
| `activity-item.blade.php` | Jump to conversation |
| `app.css` | Badge, highlight, skeleton |
| Feature + vitest | Coverage |

---

## Performance notes

- Default page = latest **50** messages (inbound∪outbound merge).  
- Older pages via `before` cursor; live via `since` cursor.  
- Queries select only list columns; no relation hydration in the merge loop.  
- Outbound preview uses in-model `displayPreview()` (no extra queries).  

---

## Testing

`php artisan test --filter='ServiceCaseEmailWorkspacePhase|OutgoingEmailReplyTest'` → **23 passed**  
`npm test -- tests/js/service-case-email-workspace-helpers.test.js` → **5 passed**

| Area | Coverage |
|------|----------|
| Auto-scroll / near-bottom | Vitest `isNearBottom` |
| Subject prefill without overwrite | Vitest + API `default_subject` |
| Send lock / duplicate prevention | Vitest `canStartSend` |
| Failed send draft preserve | Workspace JS (draft restore path) |
| Success toast | `showAppToast` on send |
| Unread badge lifecycle | Feature mark-read + count |
| Timeline jump | `data-c360-email-jump` + focus highlight |
| Live refresh cursor | Feature `since_*` delta |
| Large thread (100+) | Feature bounded to limit 50 |
| Phase 1 behaviour | Phase 1 + OutgoingEmailReply suites green |

No Dusk in repo — browser interactions covered via vitest helpers + feature API contracts.

---

## Rollback strategy

1. Revert Phase 2 JS/CSS/Blade/controller/service changes.  
2. Cache unread cursors expire harmlessly.  
3. No schema migration to reverse.  
4. Phase 1 modal + reply path remains operational.

---

## Out of scope (unchanged)

Attachments, drafts, Reply All, Forward, CC/BCC, rich text, Inbox, Labels, Template Store, separate email module, new WebSockets.
