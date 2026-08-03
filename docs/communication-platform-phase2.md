# Communication Platform Phase 2 — Runtime Switch to Template Store

**Paired canvas:** [`communication-platform-phase2.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/communication-platform-phase2.canvas.tsx)

**Date:** 2026-08-03  
**Status:** Implemented — Template Store is preferred runtime; Blade is automatic fallback  
**Scope stop:** Do not remove Blade. No Email / WhatsApp / Notifications redesign — only safe runtime source switch.

---

## 1. Root cause

Phase 1 made Template Store the editable source of truth, but customer sends still rendered Blade only. That left Super Admin edits non-operational at runtime and blocked a single shared foundation for notifications, reply playbooks, automation, and future channels.

Phase 2 flips runtime preference to **Approved Template Store**, with **Blade fallback + warning logs** so no customer communication fails.

---

## 2. Files changed

| Area | Path |
|------|------|
| Schema | `2026_08_03_210000_add_communication_template_store_phase2_columns.php` |
| Runtime | `CommunicationTemplateRuntimeService`, `CommunicationTemplateSignatureBuilder` |
| Health / compare / test | `CommunicationTemplateHealthService`, `ComparisonService`, `TestSendService` |
| Send path | `EmailChannel`, `NotificationMail` (HTML body), `emails.notifications.store-runtime` |
| Approval | `CommunicationTemplateStoreService` (`approved_version`, draft-on-edit) |
| Reply | `OutgoingEmailTemplatePreviewService` (Reply Playbooks) |
| Profile signature | User columns + Profile form (`designation`, `department`, `phone`, `company_name`, greeting) |
| Admin UI | Template list runtime/health, show compare/test-send, Health dashboard, compare view |
| Routes | `admin.communication-health.index`, `…compare`, `…test-send` |
| Tests | `CommunicationTemplateStorePhase2Test` (+ Phase 1 / EmailChannel / Reply regression) |

---

## 3. Runtime architecture

```
Notification / Reply
        │
        ▼
Approved Template Store version
        │
        ├─ render variables
        ├─ greeting (template or user Company Default)
        ├─ signature (company / user profile block / none)
        └─ send (HTML via NotificationMail)
                │
                └─ on missing Approved OR render failure
                        ▼
                   Blade template (fallback)
                        ▼
                   Log warning + increment fallback_count
```

- Preferred path: Approved Store (`approved_version`)
- Fallback path: existing Blade via `NotificationMailTemplateRegistry`
- Usage analytics recorded on successful send (count, duration, runtime source, fallback flag)

---

## 4. Blade retirement strategy

| Stage | Policy |
|-------|--------|
| Now | Blade files remain. Never deleted. |
| Runtime | Store first when Approved exists; Blade otherwise / on error |
| Verification | Communication Health + side-by-side Compare + Test Send |
| Retire Blade | Only after ≥1 stable production release with **zero** runtime fallbacks |

Do **not** remove Blade in this phase.

---

## 5. Before / after

| Concern | Before (Phase 1) | After (Phase 2) |
|---------|------------------|-----------------|
| Notification render | Blade only | Approved Store → Blade fallback |
| Super Admin edits | Visible in Store only | Publish via Approve → live runtime |
| Edit Approved | Overwrote tip version as approved tip | Edit → Draft tip; Approved snapshot stays live |
| Email Reply templates | Notification-type Blade keys | Reply Playbooks (Approved) + Blank |
| Signature | Manual / company text | Auto from profile (name, designation, dept, phone, email, company) |
| Visibility | Inventory panel | Runtime / Health / Compare / Test Send |
| Failure mode | Missing template fails | Fallback to Blade; never drop customer mail if Blade works |

---

## 6. Production safety

- Customer communication never fails solely because Store is broken — Blade always available.
- Fallback increments `fallback_count`, sets `last_error`, logs `communication_template.runtime_fallback`.
- Draft edits cannot change live runtime until Approve updates `approved_version`.
- Deprecate returns preferred runtime to Blade.
- Test Send is Super Admin manage-only.
- Ops / Agents: reply only (existing `email.reply`); no template editing.
- Admin: view / preview / health / compare (read).
- WhatsApp / automation channels unchanged; same Store foundation ready for later consumers.

---

## 7. Rollout

1. Deploy Phase 2 migration + permission seed (unchanged manage/view perms).
2. Ensure templates imported & Approved (`communication-templates:import-blade` if needed).
3. Open **Communication Health** — confirm migration progress & zero unexpected errors.
4. For each linked template: **Compare** Blade vs Store; fix copy until acceptable.
5. **Test Send** from Template Store (Super Admin).
6. Monitor `fallback_count` / Health dashboard after go-live.
7. Keep Blade until one stable release shows **zero fallbacks**.

Nav: Administration → Communication → Template Store / Communication Health.

---

## 8. Tests

`CommunicationTemplateStorePhase2Test` + regressions:

| Case | Coverage |
|------|----------|
| Runtime rendering | EmailChannel metadata `runtime_source=store` when Approved |
| Fallback | Invalid approved version → Blade + fallback_count + warning |
| Approval workflow | Edit Approved → Draft tip; Approve promotes `approved_version` |
| Comparison | Compare service + compare/health HTTP |
| Test Send | Admin forbidden; Super Admin service succeeds |
| Reply Playbooks | Approved playbooks listed; blank available |
| Automatic signature | Profile fields appear in signature HTML |
| Blade-only path | No store row still renders Blade |
| Regression | Phase 1 store tests, EmailChannelTest, OutgoingEmailReplyTest |

---

## STOP

Do **not** remove Blade. Keep Blade as fallback until at least one stable production release confirms zero runtime fallbacks.
