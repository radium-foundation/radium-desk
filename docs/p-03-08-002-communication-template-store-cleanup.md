# P[03-08]-002 Cleanup — Communication Template Store Dead Code Removal

**Date:** 2026-08-03  
**Prerequisite:** Runtime rollback already shipped (Blade-only email path)  
**Canvas:** [`p-03-08-002-communication-template-store-cleanup.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p-03-08-002-communication-template-store-cleanup.canvas.tsx)

---

## Verdict

All Communication Template Store **runtime and dead code** is removed. Blade remains the only email source. Tables and Spatie permissions are dropped via an idempotent migration. No application PHP/JS/Blade callers reference `CommunicationTemplate*` after cleanup.

Email subjects, variables, and Blade notification templates were **not** modified.

---

## 1. Runtime verification (before delete)

| Path | References CommunicationTemplate*? |
|------|-------------------------------------|
| `EmailChannel` | No — Blade via `NotificationMailTemplateRegistry` |
| `OutgoingEmailTemplatePreviewService` | No — static NotificationType list + Blade `view()` |
| `routes/web.php` | No store routes |
| `app/` (excluding store package) | Only `AppServiceProvider` policy binding (removed) |

Confirmed safe to delete the store package with no live callers.

---

## 2. Files deleted

### Services
- `app/Services/CommunicationTemplates/**` (entire directory)

### HTTP
- `app/Http/Controllers/Administration/CommunicationTemplateController.php`
- `app/Http/Controllers/Administration/CommunicationHealthController.php`
- `app/Http/Requests/Administration/StoreCommunicationTemplateRequest.php`
- `app/Http/Requests/Administration/UpdateCommunicationTemplateRequest.php`

### Policy / models / enums
- `app/Policies/CommunicationTemplatePolicy.php`
- `app/Models/CommunicationTemplate.php`
- `app/Models/CommunicationTemplateVersion.php`
- `app/Models/CommunicationTemplateUsage.php`
- `app/Enums/CommunicationTemplates/**`

### Views / assets
- `resources/views/admin/communication-templates/**`
- `resources/views/emails/notifications/store-runtime.blade.php`
- `resources/js/communication-template-editor.js`

### Console / tests / product docs
- `app/Console/Commands/ImportCommunicationTemplatesFromBladeCommand.php`
- `tests/Feature/Administration/CommunicationTemplateStoreTest.php`
- `tests/Feature/Administration/CommunicationTemplateStorePhase2Test.php`
- `tests/Feature/CommunicationTemplatePermissionSeederTest.php`
- `docs/communication-template-store-phase1.md`
- `docs/communication-platform-phase2.md`

---

## 3. Files modified

| File | Change |
|------|--------|
| `app/Providers/AppServiceProvider.php` | Removed CommunicationTemplate policy registration / imports |
| `database/seeders/RolePermissionSeeder.php` | Removed `.view` / `.manage` constants, DIRECT_ASSIGNABLE entries, Super Admin grants |
| `vite.config.js` | Removed editor entry |
| `routes/web.php` | Cleared obsolete rollback comments |
| `resources/views/navigation/administration-workspace-nav.blade.php` | Cleared obsolete comments |
| `app/Services/Notifications/Channels/EmailChannel.php` | Removed obsolete rollback docblock (behaviour unchanged) |
| `app/Services/OutgoingEmail/OutgoingEmailTemplatePreviewService.php` | Removed obsolete rollback docblock (behaviour unchanged) |

---

## 4. New migration

`database/migrations/2026_08_03_233000_drop_communication_template_store_tables.php`

- Drops (if present): `communication_template_usages` → `communication_template_versions` → `communication_templates` via `Schema::dropIfExists`
- Deletes Spatie rows for `communication-templates.view` / `.manage` (and pivots) when `permissions` table exists
- Does **not** touch unrelated tables
- `down()` is intentionally empty (irreversible data drop; recreate via original create migrations if needed)

Historical create/alter migrations (`2026_08_03_203000_*`, `2026_08_03_210000_*`) remain for migrate-from-scratch ordering; the new drop runs after them.

---

## 5. Search report (post-cleanup)

### Application runtime (`*.php` / `*.js` / `*.blade.php` excluding migrations/tests/docs)

**Zero** matches for:
- `CommunicationTemplate`
- `CommunicationTemplateVersion`
- `CommunicationTemplateUsage`
- `communication-templates` (permission / route)
- `store-runtime`
- `App\Enums\CommunicationTemplates`

### Allowed remaining matches

| Location | Why kept |
|----------|----------|
| `2026_08_03_203000_create_communication_template_store_tables.php` | Historical create migration |
| `2026_08_03_210000_add_communication_template_store_phase2_columns.php` | Historical alter (also added user profile greeting columns still used by Profile UI) |
| `2026_08_03_233000_drop_communication_template_store_tables.php` | This cleanup migration |
| `tests/Unit/Database/DropCommunicationTemplateStoreTablesMigrationTest.php` | Asserts tables/permissions gone |
| Historical investigation markdown under `docs/` | Audit trail only; not loaded by app |

### Not store-related (kept)

- `communication_template_label` on close-case metadata (`CustomerNotRespondingCloseService`) — display label string, not the store model
- User `default_greeting_style` / `company_name` profile fields — independent of deleted enums

---

## 6. Test results

| Suite | Result |
|-------|--------|
| `EmailChannelTest` | Passed |
| Outgoing email reply / IncomingEmailContent filters | Passed |
| `DropCommunicationTemplateStoreTablesMigrationTest` | Passed |
| Store Feature / permission seeder tests | Deleted |

**Note:** `DriverInstallationGuideEmailTemplateTest` has a pre-existing assertion expecting “After Installation” while Blade says “After Download”. Unrelated to this cleanup; Blade content was not changed per objectives.

---

## 7. Rollback notes

1. **Code:** Restore from git before this cleanup commit (store packages + seeder + AppServiceProvider + vite).
2. **Schema:** Re-run create/phase2 migrations only on empty DB, or restore tables from backup — `down()` of the drop migration does not recreate data.
3. **Permissions:** Re-seed `RolePermissionSeeder` after restoring constants, or insert permission rows manually.
4. **Do not** expect store runtime to return without also re-wiring `EmailChannel` (that was intentionally rolled back earlier).

---

## Recommendation

Deploy with `migrate` (or `deskd`) so production drops store tables and permission rows. No email content changes required.
