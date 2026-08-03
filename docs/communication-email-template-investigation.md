# Communication / Email Template Feature — Investigation

**Date:** 2026-08-03  
**Scope:** Read-only investigation (no code changes)  
**Canvas:** [`communication-email-template-investigation.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/communication-email-template-investigation.canvas.tsx)

---

## Verdict

The feature is fully implemented end-to-end as the **Communication Template Store** (not separate `EmailTemplate` / `OutgoingEmailTemplate` / `TemplateController` resources).

**Open the template manager at:**

```
/admin/communication/templates
```

Route name: `admin.communication-templates.index`

Navigation is **not missing entirely**. Administration workspace tabs include **Communication** (gated by `communication-templates.view`). It is **not** a top-level sidebar child, and the Administration overview body copy does not mention it. Sidebar route-context mapping for template routes is missing (falls through to Dashboard).

---

## 1. Routes found

All under `auth` + `active` middleware in `routes/web.php` (lines 378–408). **No** matching routes in `routes/api.php`.

| Method | URL | Route name | Controller |
|--------|-----|------------|------------|
| GET | `/admin/communication/templates` | `admin.communication-templates.index` | `CommunicationTemplateController@index` |
| GET | `/admin/communication/templates/create` | `admin.communication-templates.create` | `…@create` |
| POST | `/admin/communication/templates` | `admin.communication-templates.store` | `…@store` |
| POST | `/admin/communication/templates/import-blade` | `admin.communication-templates.import-blade` | `…@importBlade` |
| GET | `/admin/communication/templates/{communication_template}` | `admin.communication-templates.show` | `…@show` |
| GET | `/admin/communication/templates/{communication_template}/edit` | `admin.communication-templates.edit` | `…@edit` |
| PUT | `/admin/communication/templates/{communication_template}` | `admin.communication-templates.update` | `…@update` |
| GET | `/admin/communication/templates/{communication_template}/compare` | `admin.communication-templates.compare` | `…@compare` |
| POST | `/admin/communication/templates/{communication_template}/preview` | `admin.communication-templates.preview` | `…@preview` |
| POST | `/admin/communication/templates/{communication_template}/approve` | `admin.communication-templates.approve` | `…@approve` |
| POST | `/admin/communication/templates/{communication_template}/deprecate` | `admin.communication-templates.deprecate` | `…@deprecate` |
| POST | `/admin/communication/templates/{communication_template}/rollback` | `admin.communication-templates.rollback` | `…@rollback` |
| POST | `/admin/communication/templates/{communication_template}/test-send` | `admin.communication-templates.test-send` | `…@testSend` |
| GET | `/admin/communication/health` | `admin.communication-health.index` | `CommunicationHealthController` (invokable) |

### Names searched but not found as resources

| Search term | Result |
|-------------|--------|
| `EmailTemplate` | No model/controller/routes. Only unit test class names under `tests/Unit/Notifications/*EmailTemplateTest.php` (Blade content assertions). |
| `OutgoingEmailTemplate` | No model/CRUD. Service only: `OutgoingEmailTemplatePreviewService` (Reply Playbooks). |
| `TemplateController` | Does not exist. CRUD is `CommunicationTemplateController`. |
| `email-templates` / `outgoing-email` routes | Not present for this feature. |

---

## 2. Controllers responsible for template CRUD

| Class | Path | Methods |
|-------|------|---------|
| `CommunicationTemplateController` | `app/Http/Controllers/Administration/CommunicationTemplateController.php` | `index`, `create`, `store`, `show`, `edit`, `update`, `approve`, `deprecate`, `rollback`, `compare`, `testSend`, `preview`, `importBlade`. Uses `authorizeResource(CommunicationTemplate::class, 'communication_template')`. **No** `destroy`. |
| `CommunicationHealthController` | `app/Http/Controllers/Administration/CommunicationHealthController.php` | `__invoke` — authorizes `viewAny` |

**Form requests:**

- `app/Http/Requests/Administration/StoreCommunicationTemplateRequest.php`
- `app/Http/Requests/Administration/UpdateCommunicationTemplateRequest.php`

---

## 3. Blade / Vue / Livewire pages

**Blade only** — no Vue SPA pages, no Livewire, no Inertia for this feature.

| View | Path |
|------|------|
| Index (Template Store) | `resources/views/admin/communication-templates/index.blade.php` |
| Create | `resources/views/admin/communication-templates/create.blade.php` |
| Edit | `resources/views/admin/communication-templates/edit.blade.php` |
| Show | `resources/views/admin/communication-templates/show.blade.php` |
| Compare | `resources/views/admin/communication-templates/compare.blade.php` |
| Health | `resources/views/admin/communication-templates/health.blade.php` |
| Form partial | `resources/views/admin/communication-templates/partials/form.blade.php` |

**JS:** `resources/js/communication-template-editor.js` (Vite entry). Loaded via `@vite` on create/edit.

---

## 4. Permissions and feature flags

### Permissions (`RolePermissionSeeder`)

| Permission | Constant |
|------------|----------|
| `communication-templates.view` | `PERMISSION_COMMUNICATION_TEMPLATES_VIEW` |
| `communication-templates.manage` | `PERMISSION_COMMUNICATION_TEMPLATES_MANAGE` |

### Role grants

| Role | View | Manage |
|------|------|--------|
| `admin` | yes | no |
| `operations_admin` | no | no |
| `superadmin` | yes | yes |

### Policy

`app/Policies/CommunicationTemplatePolicy.php`

- `viewAny` / `view` → `.view`
- `create` / `update` / `manage` → `.manage`

Registered via `Gate::policy` in `AppServiceProvider`.

### Feature flags

**None.** No config keys under `config/` gate this feature. Access is permission + policy only (plus `auth` / `active` middleware).

---

## 5. Navigation — present in workspace tabs; not in main sidebar

| Surface | Communication / templates link? |
|---------|----------------------------------|
| **Administration workspace nav** | **Yes** — tab label `Communication` → `admin.communication-templates.index` when `Gate::check('viewAny', CommunicationTemplate::class)`. File: `resources/views/navigation/administration-workspace-nav.blade.php` |
| **Main sidebar** | **Only** “Administration” → `/admin/administration`. No child “Communication” item. |
| **Administration overview body** | **No** dedicated card/link — copy says “users, settings, and holidays” (`resources/views/admin/administration/index.blade.php`). Access is via workspace **tabs** above. |
| **Platform / Mission Control** | No Template Store link found. |
| **Sidebar active context** | `admin.communication-templates.*` and `admin.communication-health.*` are **not** matched in `NavigationContextResolver::resolveRouteContext`; unmatched routes fall through to **Dashboard**. |
| **`canAccessAdministration`** | Does **not** include CommunicationTemplate permission; Admin/Superadmin still see Administration via users/settings/holidays. |

**Answer to “did Administration forget the page?”:** Not entirely. The workspace tab exists. Discoverability gaps: no overview body mention, no sidebar child, and broken Administration highlighting on template routes.

---

## 6. Backend depth — not API-only

Full stack is implemented: models, migrations, services, Blade UI, runtime, health, reply playbooks, artisan import, tests, docs.

### Models

- `app/Models/CommunicationTemplate.php`
- `app/Models/CommunicationTemplateVersion.php`
- `app/Models/CommunicationTemplateUsage.php`
- **No** `EmailTemplate` or `OutgoingEmailTemplate` model

### Migrations

- `database/migrations/2026_08_03_203000_create_communication_template_store_tables.php`
- `database/migrations/2026_08_03_210000_add_communication_template_store_phase2_columns.php`

### Services (`app/Services/CommunicationTemplates/`)

Store, Runtime, Preview, Health, Comparison, TestSend, BladeImporter, SignatureBuilder, VariableCatalog  
Plus `app/Services/OutgoingEmail/OutgoingEmailTemplatePreviewService.php`

### Artisan

`communication-templates:import-blade` (`ImportCommunicationTemplatesFromBladeCommand`)

### Tests

- `tests/Feature/Administration/CommunicationTemplateStoreTest.php`
- `tests/Feature/Administration/CommunicationTemplateStorePhase2Test.php`

### Docs

- `docs/communication-template-store-phase1.md`
- `docs/communication-platform-phase2.md`

Documented path: **Administration → Communication → Template Store**.

---

## 7. Exact URL that opens the template manager

| Purpose | URL | Route name |
|---------|-----|------------|
| **Template manager (index)** | `/admin/communication/templates` | `admin.communication-templates.index` |
| Create | `/admin/communication/templates/create` | `admin.communication-templates.create` |
| Health | `/admin/communication/health` | `admin.communication-health.index` |

Requires: authenticated active user with `communication-templates.view` (Admin or Superadmin by default). Manage actions require `communication-templates.manage` (Superadmin by default).

---

## Files found (summary)

| Area | Paths |
|------|-------|
| Routes | `routes/web.php` (378–408) |
| Controllers | `app/Http/Controllers/Administration/CommunicationTemplateController.php`, `CommunicationHealthController.php` |
| Policy | `app/Policies/CommunicationTemplatePolicy.php` |
| Views | `resources/views/admin/communication-templates/*` |
| Nav | `resources/views/navigation/administration-workspace-nav.blade.php` |
| JS | `resources/js/communication-template-editor.js` |
| Models | `CommunicationTemplate`, `CommunicationTemplateVersion`, `CommunicationTemplateUsage` |
| Permissions | `database/seeders/RolePermissionSeeder.php` |
| Context gap | `app/Support/Navigation/NavigationContextResolver.php` |

---

## Existing permissions

- `communication-templates.view` — list/show/health
- `communication-templates.manage` — create/edit/approve/deprecate/rollback/import/test-send

---

## Missing navigation

1. No top-level sidebar child under Administration.
2. Administration overview body does not mention Communication / Template Store.
3. `NavigationContextResolver` does not map `admin.communication-templates.*` or `admin.communication-health.*` → Administration (sidebar highlights Dashboard instead).
4. `canAccessAdministration` omits CommunicationTemplate `viewAny` (edge case if a user had only template permissions).

---

## Recommendation

1. **Use the feature now:** Superadmin/Admin → **Administration** → workspace tab **Communication**, or open `/admin/communication/templates` directly.
2. **Improve discoverability (optional):** add an overview card/link on Administration home; map template + health routes in `NavigationContextResolver` so the sidebar stays on Administration.
3. **Do not search for** `EmailTemplate` / `email-templates` / `TemplateController` routes — they do not exist. The product name is **Template Store** under **Communication**.
4. No need to rebuild UI — it already exists behind permissions, not feature flags.
