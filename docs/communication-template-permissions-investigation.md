# Communication Template Permissions — Investigation

**Date:** 2026-08-03  
**Scope:** Read-only root-cause analysis, then minimal fix (documented below)  
**Canvas:** [`communication-template-permissions-investigation.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/communication-template-permissions-investigation.canvas.tsx)

---

## Verdict

`communication-templates.view` and `communication-templates.manage` are **not constants-only**. They are wired into `RolePermissionSeeder` insertion and role assignment. They are **missing from the database** because Spatie permission rows are created only when the seeder runs — **migrations never insert them**.

After a deploy that ran `migrate` without `db:seed --class=RolePermissionSeeder` (or before that commit was seeded), this is expected:

```php
Spatie\Permission\Models\Permission::where('name','communication-templates.view')->first(); // null
```

`deskd` / `tools/commands/deploy.sh` **does** seed after migrate. Alternate deploy paths and the tools README checklist often do not.

---

## 1. RolePermissionSeeder — constants vs insertion

| Item | Status |
|------|--------|
| `PERMISSION_COMMUNICATION_TEMPLATES_VIEW` = `communication-templates.view` | Defined (lines 71–73) |
| `PERMISSION_COMMUNICATION_TEMPLATES_MANAGE` = `communication-templates.manage` | Defined |
| Included in `DIRECT_ASSIGNABLE_PERMISSIONS` | **Yes** (lines 85–86) |
| Included in `ROLE_PERMISSIONS` | **Yes** — see role matrix below |
| Created in `run()` via `Permission::findOrCreate(..., 'web')` | **Yes** — collected from flattened `ROLE_PERMISSIONS` + `DIRECT_ASSIGNABLE_*` + other merges (lines 376–386) |

They are **not** constants-only. When the seeder executes, both rows are inserted.

### Local reproduction

Before seeding (this workspace DB): both queries returned `null`.  
After `php artisan db:seed --class=RolePermissionSeeder`: both exist; Super Admin has view+manage; Admin has view only; Operations Admin has neither.

---

## 2. Permission arrays / groups / role assignment

### Insertion sources (`run()`)

```
ROLE_PERMISSIONS (flatten)
  ∪ DIRECT_ASSIGNABLE_PERMISSIONS   ← includes both communication-templates.*
  ∪ WORKFORCE_TEAM_VISIBILITY_PERMISSIONS
  ∪ FINANCE_MODULE_VIEW_PERMISSIONS
→ unique → Permission::findOrCreate($name, 'web')
→ foreach role: Role::findOrCreate → syncPermissions(permissionsForRole(...))
```

### Permission groups (`UserAccessPermissionCatalog`)

**Not listed.** Catalog only exposes `orders.correct-identity` under “Operations”. That affects the Users → Access Permissions UI only — **it does not create Spatie rows**. Absence from the catalog does **not** explain `Permission::where(...)->first() === null`.

### Role assignment (current seeder matrix)

| Role | `.view` | `.manage` |
|------|---------|-----------|
| `superadmin` | yes | yes |
| `admin` | yes | no |
| `operations_admin` | no | no |
| Support roles (`agent`, `support_specialist`, …) | no | no |

---

## 3. Super Admin receipt

When the seeder **has** run, Super Admin receives both:

- `communication-templates.view`
- `communication-templates.manage`

Verified after a fresh seed in this environment.

---

## 4. Why production query returns null

1. Spatie `permissions` rows are **application-seeded**, not schema-migrated. The only permission migration is `create_permission_tables` (empty tables).
2. Feature commit `8942d3b` added the constants + arrays to `RolePermissionSeeder`, but **did not add a data migration**.
3. Deploy that only runs `php artisan migrate --force` (or follows tools README steps that omit seeding) leaves the new names absent.
4. `Permission::where('name', ...)->first()` hits the DB directly — null means the row was never inserted (not a policy/cache illusion).
5. `deskd` seeds (`deploy.sh` lines 68–70), so a full `deskd` **after** that commit should create them. Null implies that path was not completed against this database, or an older code tree was seeded.

**Not the cause:** constants unused; typos in permission names; missing from `UserAccessPermissionCatalog`.

---

## 5. Desired vs current matrix (gap)

User requirement for the fix: **only Super Admin** receives these permissions; Admin / Operations / Support must not.

Current seeder also grants **`communication-templates.view` to `admin`**. That must be removed as part of the minimal fix so re-seeding matches the intended matrix.

---

## 6. Minimal fix (applied)

1. **Seeder:** Both permissions remain in `DIRECT_ASSIGNABLE_PERMISSIONS` (ensures `findOrCreate`). Both remain on `ROLE_SUPERADMIN`. **Removed** `PERMISSION_COMMUNICATION_TEMPLATES_VIEW` from `ROLE_ADMIN`. All other role permissions untouched.
2. **Operational:** Re-run `php artisan db:seed --class=RolePermissionSeeder --force` (or `deskd`) so production inserts rows and syncs Super Admin only.
3. **Test:** `tests/Feature/CommunicationTemplatePermissionSeederTest.php` — seeds twice; asserts both permissions exist once; Super Admin has both; Admin / Operations Admin / Agent / Support Specialist do not.

`CommunicationTemplateStoreTest` updated: Admin is forbidden from view and manage (matches Super-Admin-only matrix).

No unrelated permission names changed.

---

## Files involved

| File | Role |
|------|------|
| `database/seeders/RolePermissionSeeder.php` | Defines, inserts, assigns |
| `tools/commands/deploy.sh` | Seeds after migrate on `deskd` |
| `tools/README.md` | Deploy checklist **omits** seed step (docs drift) |
| `app/Support/UserAccessPermissionCatalog.php` | UI groups — does not include these perms |
| `app/Policies/CommunicationTemplatePolicy.php` | Checks Spatie permission names |

---

## Recommendation

1. Treat missing rows as **seed-not-run**, not missing seeder code.
2. Apply Super-Admin-only matrix (drop Admin view grant).
3. Re-seed on production (or deploy via `deskd`).
4. Optionally align tools README with `deploy.sh` seed step.
5. Add regression test so future permission adds stay Super-Admin-scoped and idempotent.
