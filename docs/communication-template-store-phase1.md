# Communication Template Store — Phase 1

**Paired canvas:** [`communication-template-store-phase1.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/communication-template-store-phase1.canvas.tsx)

**Date:** 2026-08-03  
**Status:** Implemented — Blade remains runtime; Template Store is editable source of truth  
**Scope stop:** No runtime switch. No AI generation. No notification / Email Reply / WhatsApp / automation behaviour changes.

---

## 1. Root cause

Customer-facing copy lived only in Blade notification views and `NotificationMailTemplateRegistry`. Changing wording required a code deploy. There was no admin-facing store for categories, channels, version history, approval, preview, or usage — so Super Admin could not manage communications safely without engineering.

Phase 1 fixes ownership of **content**, not delivery: Template Store becomes the editable single source of truth; outbound send paths still render Blade.

---

## 2. Files changed

| Area | Path |
|------|------|
| Schema | `database/migrations/2026_08_03_203000_create_communication_template_store_tables.php` |
| Models | `app/Models/CommunicationTemplate.php`, `CommunicationTemplateVersion.php`, `CommunicationTemplateUsage.php` |
| Enums | `app/Enums/CommunicationTemplates/*` |
| Services | `app/Services/CommunicationTemplates/*` |
| HTTP | `CommunicationTemplateController`, Store/Update requests, `CommunicationTemplatePolicy` |
| UI | `resources/views/admin/communication-templates/*`, `resources/js/communication-template-editor.js` |
| Nav / routes | `administration-workspace-nav.blade.php`, `routes/web.php` |
| Permissions | `RolePermissionSeeder.php` (`communication-templates.view` / `.manage`) |
| Import | `ImportCommunicationTemplatesFromBladeCommand` (`communication-templates:import-blade`) |
| Registry safety | `NotificationMailTemplateRegistry::resolve()` default → `null` (non-mail types) |
| Vite | `vite.config.js` entry for editor JS |
| Tests | `tests/Feature/Administration/CommunicationTemplateStoreTest.php` |

---

## 3. Template architecture

```
Administration → Communication → Template Store
        │
        ├─ communication_templates          (identity, category, channels, status, usage, blade link)
        ├─ communication_template_versions  (immutable versions: subject, greeting, body, signature, reason)
        └─ communication_template_usages    (used_at, used_by, channel, communication_type)
```

**Runtime (unchanged):** `NotificationMailTemplateRegistry` → Blade view → SMTP/ZeptoMail  
**Editable source (new):** Template Store rows with `runtime_source = blade` and optional `notification_type` / `blade_view` linkage  
**Phase 2 (not done):** Switch senders to Approved Template Store versions

### Capabilities in Phase 1

| Capability | Behaviour |
|------------|-----------|
| Categories | Refund, Support, Appointment, Sales, Finance, General, Internal, WhatsApp, SMS, Future |
| Channels | Email (+ WhatsApp / SMS / Internal Note marked future); multi-select |
| Status | Draft → Approved → Deprecated; only Approved intended for future agent use |
| Versioning | Every save / rollback appends a new version; history never overwritten |
| Preview | Sample-variable render; desktop + mobile frame; live on edit |
| Variables | Sidebar catalog (`{{customer_name}}`, `{{order_id}}`, …) expandable |
| Signature | Company default / User signature / None |
| Permissions | Super Admin full; Admin read-only; Operations / Agents no access |

---

## 4. Blade migration inventory

**Customer mail Blades imported from registry: 12**  
**Excluded:** `support_appointment_assigned` — no customer mail Blade (staff/internal)  
**Not removed:** All Blade files remain; layouts / partials / preview design-system views stay as shared infrastructure

| Notification type | Category | Blade view | Exists |
|-------------------|----------|------------|--------|
| request_serial_number | support | emails.notifications.request-serial-number | yes |
| request_correct_serial | support | emails.notifications.request-correct-serial | yes |
| customer_waiting_followup | support | emails.notifications.customer-waiting-followup | yes |
| callback_schedule | support | emails.notifications.callback-schedule | yes |
| final_reminder_before_closure | support | emails.notifications.final-reminder-before-closure | yes |
| support_appointment_booked | appointment | emails.notifications.support-appointment-booked | yes |
| service_case_closed | support | emails.notifications.service-case-closed | yes |
| driver_installation_guide | support | emails.notifications.driver-installation-guide | yes |
| review_request | sales | emails.notifications.review-request | yes |
| refund_confirmation | refund | emails.notifications.refund-confirmation | yes |
| buy_rd_service | sales | emails.notifications.buy-rd-service | yes |
| buy_product | sales | emails.notifications.buy-product | yes |

**Import command**

```bash
php artisan communication-templates:import-blade --dry-run
php artisan communication-templates:import-blade
# or from UI: Template Store → Import from Blade
```

Import extracts `@section('content')`, maps `$var` → `{{var}}`, creates Approved store rows, sets `runtime_source=blade`. Re-import skips already-imported notification types.

---

## 5. Before / after

| | Before | After (Phase 1) |
|--|--------|-----------------|
| Edit copy | Deploy Blade change | Super Admin edits Template Store (versioned) |
| Visibility | Code only | Admin list + Blade inventory panel |
| Approval | None | Draft / Approved / Deprecated |
| Preview | Manual send / local render | Live sample preview (desktop/mobile) |
| History | Git only | Immutable version table + rollback → new version |
| Runtime sends | Blade | **Still Blade** |
| Email Reply | Blade registry templates | **Unchanged** |
| WhatsApp / automation | Existing paths | **Unchanged** |

---

## 6. Production safety

- No sender, notification mailer, Email Reply, WhatsApp, or automation code path switched to Template Store.
- Imported rows keep `runtime_source = blade`.
- Blade files are not deleted.
- Registry `resolve()` returns `null` for non-mail types instead of throwing (inventory-safe).
- Ops / Agents cannot open Template Store; Admin cannot mutate.
- Usage recording API exists for Phase 2 consumers; Phase 1 senders do not write usage yet.
- MySQL FK names shortened (`ctv_template_fk`, `ctu_*`) to stay within identifier limits.

---

## 7. Rollout plan

1. Deploy migration + permissions seed (`RolePermissionSeeder` / equivalent sync).
2. Build frontend assets (`vite` includes `communication-template-editor.js`).
3. Super Admin: open **Administration → Communication → Template Store**.
4. Run `communication-templates:import-blade` (or UI Import).
5. Spot-check each imported template preview vs live Blade.
6. Edit only in Template Store going forward; **do not** change runtime yet.
7. **Phase 2 gate:** after every template is verified, switch Approved store → runtime and keep Blade as fallback until confident.

Future consumers (Email Reply, Automation, Refund, Appointment, WhatsApp, Customer Notifications) must all resolve the same Approved template key.

---

## 8. Tests

`tests/Feature/Administration/CommunicationTemplateStoreTest.php`

| Case | Coverage |
|------|----------|
| CRUD + revise | Create draft, approve, update → v2 |
| Versioning / rollback | Rollback to v1 creates v3; history preserved |
| Permissions | Super Admin manage; Admin view-only; Ops + Agent forbidden |
| Preview | Variable substitution in subject/body |
| Migration inventory | 12 types inventoriable; import then skip |
| Runtime safety | Imported rows remain `runtime_source=blade` |

Regression: `OutgoingEmailReplyTest` still passes (reply path untouched).

---

## STOP

Phase 1 complete. **Do not** switch runtime away from Blade until Phase 2 after every template is verified.
