# P[03-08]-002 — Communication Template Store Safe Rollback

> **Superseded for dead-code status:** final cleanup is documented in
> [`docs/p-03-08-002-communication-template-store-cleanup.md`](p-03-08-002-communication-template-store-cleanup.md).
> Store packages, permissions, and tables have been removed. This document remains
> as the runtime-rollback record.

**Date:** 2026-08-03  
**Ticket:** P[03-08]-002  
**Canvas:** [`p-03-08-002-communication-template-store-rollback.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p-03-08-002-communication-template-store-rollback.canvas.tsx)

---

## Verdict

Blade templates are again the **only** runtime email source. Live notification sends and C360 reply template previews no longer call `CommunicationTemplate*` services. Admin Template Store routes and the Administration **Communication** tab are removed. Database tables and migrations are **intentionally retained**. Store code remains in-tree as dead code for a future cleanup.

Email content is unchanged: sends use the existing `resources/views/emails/notifications/*.blade.php` files via `NotificationMailTemplateRegistry` + `NotificationMail` (no pre-rendered store HTML).

---

## Goals vs outcome

| Goal | Outcome |
|------|---------|
| 1. Blade as only runtime email source | Done — `EmailChannel` restored to registry + Blade mailable |
| 2. Remove runtime deps on CommunicationTemplate* | Done — no live send/reply path injects store services |
| 3. Hide Template UI/routes from production | Done — routes unregistered; nav tab removed |
| 4. Keep DB tables/migrations | Intact — no migration drops |
| 5. Convert remaining Template Store refs to Blade | Done — reply preview restored to `view($blade)->render()` |
| 6. Report files / deps / retained / dead code | This document + canvas |

---

## Root cause of prior dual-runtime

Phase 1/2 wired `EmailChannel` through `CommunicationTemplateRuntimeService`, which preferred approved store versions and fell back to Blade. Hiding the admin UI alone would **not** have restored Blade-only sends while approved store rows existed (`findApprovedForNotificationType` ignored deprecate status).

---

## Files changed

| File | Change |
|------|--------|
| `app/Services/Notifications/Channels/EmailChannel.php` | Removed `CommunicationTemplateRuntimeService`; send via Blade registry only |
| `app/Services/OutgoingEmail/OutgoingEmailTemplatePreviewService.php` | Restored fixed Blade template list + Blade preview; no playbooks/store |
| `routes/web.php` | Removed `admin.communication-templates.*` and `admin.communication-health.index` |
| `resources/views/navigation/administration-workspace-nav.blade.php` | Removed Communication tab |
| `resources/views/customer-360/partials/incoming-email-modal.blade.php` | Label “Reply Playbook” → “Template” |
| `tests/Unit/Notifications/EmailChannelTest.php` | Dropped runtime constructor arg |
| `tests/Feature/Administration/CommunicationTemplateStoreTest.php` | Skipped (P[03-08]-002) |
| `tests/Feature/Administration/CommunicationTemplateStorePhase2Test.php` | Skipped (P[03-08]-002) |

---

## Runtime dependencies removed

| Former consumer | Former dependency | Restored path |
|-----------------|-------------------|---------------|
| `EmailChannel::send` | `CommunicationTemplateRuntimeService::renderNotificationMessage` + `recordSuccessfulSend` | `NotificationMailTemplateRegistry` → `NotificationMail` (Blade view) |
| `OutgoingEmailTemplatePreviewService::availableTemplates` | `CommunicationTemplate` query + reply playbooks | Static `NotificationType` list |
| `OutgoingEmailTemplatePreviewService::preview` | `renderStoreVersion` / `renderNotificationMessage` / signature builder | `view($definition->view, $variables)->render()` |
| Blank reply body | Auto greeting + user signature via store helpers | `<p></p>` |

**Still used for email (unchanged):**

- `NotificationMailTemplateRegistry`
- `NotificationMail`
- `resources/views/emails/notifications/*.blade.php` (except store-only wrapper, unused)
- `NotificationDispatcher` / channel wiring

---

## Database objects intentionally retained

| Object | Notes |
|--------|-------|
| Migration `2026_08_03_203000_create_communication_template_store_tables.php` | Kept |
| Migration `2026_08_03_210000_add_communication_template_store_phase2_columns.php` | Kept |
| Tables `communication_templates`, `communication_template_versions`, `communication_template_usages` | Not dropped |
| Spatie permissions `communication-templates.view` / `.manage` | Left in seeder; inert without UI routes |
| Profile columns used by Phase 2 signatures (if present) | Untouched; unused by Blade blank reply |

---

## Dead code eligible for future cleanup

Do **not** delete in this rollback; safe to remove in a later ticket once confirmed unused:

### Services
- `app/Services/CommunicationTemplates/*` (Runtime, Store, Preview, Health, Comparison, TestSend, BladeImporter, SignatureBuilder, VariableCatalog)

### HTTP / policy
- `CommunicationTemplateController`, `CommunicationHealthController`
- `StoreCommunicationTemplateRequest`, `UpdateCommunicationTemplateRequest`
- `CommunicationTemplatePolicy` (+ `AppServiceProvider` Gate registration)

### Models / enums
- `CommunicationTemplate`, `CommunicationTemplateVersion`, `CommunicationTemplateUsage`
- `app/Enums/CommunicationTemplates/*`

### UI / assets
- `resources/views/admin/communication-templates/**`
- `resources/js/communication-template-editor.js` (+ Vite entry)
- `resources/views/emails/notifications/store-runtime.blade.php`

### Console / tests / docs
- `ImportCommunicationTemplatesFromBladeCommand` (`communication-templates:import-blade`)
- Skipped store Feature tests + permission seeder test (optional keep for seeder matrix)
- Docs: `docs/communication-template-store-phase1.md`, `docs/communication-platform-phase2.md`, related canvases

---

## Verification

- `php artisan route:list --name=communication` → only unrelated workspace communication-actions route
- EmailChannel + OutgoingEmail reply tests: **passed**
- Store Feature tests: **skipped** with P[03-08]-002 reason

---

## Operational notes

1. Existing store rows in production (if any) are ignored by runtime; no data purge required.
2. Do **not** re-import via `communication-templates:import-blade` expecting live effect — command still exists but does not affect sends.
3. Future cleanup can drop tables only after explicit approval (out of scope here).

---

## Recommendation

Ship this rollback as P[03-08]-002. Schedule a follow-up cleanup ticket to delete dead store packages, views, permissions, and optionally drop tables after a soak period.
