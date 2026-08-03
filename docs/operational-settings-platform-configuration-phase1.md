# Operational Settings vs Platform Configuration — Phase 1 Report

**Status:** Implemented  
**Scope:** Visibility and navigation only. No configuration storage, API, or route removals.

---

## 1. Root cause

Administration exposed a single **System Settings** surface (`admin.system-settings.index`) to every user with `system-settings.manage` (Admin, Operations Admin, Super Admin).

That page mixed:

- Day-to-day operational controls (notifications, performance, realtime, automation, feature flags)
- Platform configuration chrome (Cashfree / Gmail / Telegram / Interakt / Meta / SMTP presence, Environment, Version/build, Audit History, Advanced diagnostics, Platform monitoring links)

Normal admins could **see** platform configuration details they could not meaningfully own or change (many values are env/superadmin-gated). That leaked unnecessary platform information into the Administration IA.

There was no dedicated authorization point separating **Operational Settings** from **Platform Configuration**.

---

## 2. Files changed

| File | Change |
|---|---|
| `app/Support/Administration/PlatformConfigurationAccess.php` | **New** single auth point `canManage()` |
| `app/Providers/AppServiceProvider.php` | Gate `managePlatformConfiguration` → auth point |
| `app/Http/Controllers/OperationalSystemSettingsController.php` | `index` = operational surface; `platformConfiguration` = superadmin surface + 403 |
| `routes/web.php` | **Added** `admin.platform-configuration.index` (existing routes kept) |
| `resources/views/navigation/administration-workspace-nav.blade.php` | Rename Settings → Operational Settings; add Platform Configuration tab |
| `resources/views/admin/system-settings/index.blade.php` | Split render by `settingsSurface` |
| `resources/views/admin/system-settings/partials/operational-center-section.blade.php` | Hide integration/SMTP/API chrome when operational |
| `resources/views/admin/system-settings/partials/realtime-card.blade.php` | Hide Platform monitoring link on operational surface |
| `resources/views/admin/system-settings/partials/preserve-settings-inputs.blade.php` | **New** hidden inputs so PUT contract unchanged |
| `resources/views/components/system-settings/header.blade.php` | Optional Audit History button |
| `app/Support/Settings/SettingsCenterNav.php` | Sidebar hides Overview / Environment / Advanced / Observe for non–super-admins |
| `resources/views/admin/administration/index.blade.php` | Copy + Platform Configuration CTA for superadmin |
| `tests/Feature/Administration/PlatformConfigurationVisibilityTest.php` | **New** Phase 1 visibility/403 tests |
| Related expectation updates | `AdministrationHomeTest`, `OperationalSystemSettingsTest`, `PerformanceSystemSettingsTest`, `FourMenuNavigationTest` |

**Not changed:** configuration storage, `config/system_settings.php` definitions, settings services, Cashfree/Gmail processors, Platform dashboard logic, Spatie permission grants (visibility only).

---

## 3. Before / after IA

### Before

```
Administration
├── Overview
├── Users & Roles
├── Settings          ← full System Settings (ops + platform chrome)
└── Holiday Calendar
```

### After

```
Administration
├── Overview
├── Users & Roles
├── Operational Settings     ← ops controls only (Admin+)
├── Holiday Calendar
└── Platform Configuration   ← SUPER ADMIN ONLY
```

| Surface | Route | Who |
|---|---|---|
| Operational Settings | `GET /admin/system-settings` (`admin.system-settings.index`) | `system-settings.manage` |
| Platform Configuration | `GET /admin/platform-configuration` (`admin.platform-configuration.index`) | Super Admin only |
| Update endpoint | `PUT /admin/system-settings` (unchanged name) | `system-settings.manage` (existing rules) |

Operational Settings **shows:** Operational Center (feature flags without integration cards), Realtime, Performance, Automation, Notifications.

Operational Settings **hides:** Configuration Overview, Environment, SMTP/API/Cashfree/Gmail/Telegram/Interakt/Meta widgets, Version/build, Audit History, Advanced, Platform links.

Platform Configuration **shows** the previous full platform-oriented System Settings content for Super Admin.

---

## 4. Authorization model

**Single point:**

```php
PlatformConfigurationAccess::canManage(?User $user): bool
// true iff user has role `superadmin`
```

**Gate alias (for Blade / `@can`):**

```php
Gate::define('managePlatformConfiguration', fn (?User $user) =>
    PlatformConfigurationAccess::canManage($user)
);
```

| Check | Where |
|---|---|
| Nav tab visibility | `administration-workspace-nav` uses `PlatformConfigurationAccess::canManage` |
| Direct URL 403 | `OperationalSystemSettingsController::platformConfiguration` → `abort_unless(..., 403)` |
| Sidebar platform items | `SettingsCenterNav` uses same access helper |
| Home CTA | `@can('managePlatformConfiguration')` |

Role checks are **not** scattered as ad-hoc `hasRole('superadmin')` for this feature beyond the single access class (Gate delegates to it).

Operational Settings continue to use existing `SystemSettingPolicy` / `system-settings.manage`. No permission seed changes.

---

## 5. Production safety

| Concern | Result |
|---|---|
| Routes removed? | **No** — only added `admin.platform-configuration.index` |
| API / config storage changed? | **No** |
| Admin can still open Operational Settings? | **Yes** |
| Admin Platform Configuration URL | **403** |
| Super Admin sees both surfaces | **Yes** |
| Settings PUT still requires full key set? | **Yes** — operational form preserves hidden platform keys so validation contract is unchanged |
| Cashfree / payment paths | **Untouched** |
| Platform dashboard | **Untouched** |

---

## 6. Regression analysis

| Area | Risk | Mitigation |
|---|---|---|
| Admin settings update missing keys | High if sections removed naively | Hidden `preserve-settings-inputs` for email/whatsapp/telegram/outbox/system/hybrid |
| Super Admin loses platform UI | Medium | Dedicated Platform Configuration route with full prior chrome |
| Nav label break (“Settings”) | Low | Tests updated to “Operational Settings” |
| Sidebar still leaking Overview | Medium | `SettingsCenterNav` gated by same auth point |
| Realtime “Open Platform monitoring” on ops page | Low | Link gated with `showPlatformLinks` |
| Duplicate form fields | Controlled | Feature-flag list excludes platform category keys on operational surface |

---

## 7. Tests

New: `tests/Feature/Administration/PlatformConfigurationVisibilityTest.php`

- Admin cannot see Platform Configuration nav
- Admin gets **403** on Platform Configuration URL
- Admin Operational Settings hides platform surfaces
- Super Admin sees Platform Configuration + full platform content
- Super Admin Operational Settings remains available
- Auth point is Super Admin only
- Existing operational route still OK for Admin

Updated expectations in Administration / Operational / Performance / Four-menu navigation tests for renamed IA.

**Verified run:** `PlatformConfigurationVisibilityTest` + `AdministrationHomeTest` + `OperationalSystemSettingsTest` + `PerformanceSystemSettingsTest` + `RealtimeSystemSettingsTest` → **36 passed**.

---

## STOP

Phase 1 complete: Operational Settings separated from Platform Configuration by visibility and one authorization point. No settings redesign, no page merge, no configuration logic rewrite.
