# P-16-24 — OEM two-way integration deploy plan (PRE-APPROVAL)

**Risk:** HIGH — database migration, OEM session data, administrator confirmations.

## Production baseline (unchanged by this document)

- Radium Desk **v4.1.69** / `50d43ada1`
- Extension **v0.3.5** (after merge) replaces **v0.3.4** for two-way capture
- Flags **default OFF** (`DASHBOARD_OEM_HANDOFF_ENABLED`, `DASHBOARD_OEM_HANDOFF_MANTRA_CAPTURE`, `DASHBOARD_OEM_HANDOFF_MORPHO_CAPTURE`)

## Owner approval checklist (before deploy)

1. **Backup:** logical DB backup + existing KVM snapshot policy per release gate.
2. **Migration:** apply **in order**:
   - `2026_11_13_230000_create_dashboard_oem_handoff_sessions_table` (if still Pending)
   - `2026_11_16_100000_add_oem_handoff_session_decisions`
   with `DEPLOY_RUN_MIGRATIONS=1` only on authorized deploy.
3. **Flags (pilot only):** enable `DASHBOARD_OEM_HANDOFF_ENABLED=1` and per-vendor capture flags after IT distributes extension **v0.3.5**.
4. **Extension:** load unpacked **v0.3.5** ZIP on administrator workstations; verify manifest lists `mantra-fill.bundle.js` and `morpho-fill.bundle.js`.
5. **Smoke:** Mantra + Morpho icon → OEM tab → manual CAPTCHA/Check or Morpho validation → Desk results panel → **Confirm** stores `confirmed_payload` only (no service-case overwrite).

## Rollback

1. Disable all `DASHBOARD_OEM_HANDOFF_*` flags.
2. Revert Desk app release if needed; migration columns are nullable — no data loss on rollback.
3. Reinstall extension **v0.3.4** for serial prefill-only if capture must be disabled.

## Explicitly NOT authorized in P-16-24

- Production deploy, flag enablement, or migration execution on KVM.
- Payment, Cashfree, or fulfilment logic changes.