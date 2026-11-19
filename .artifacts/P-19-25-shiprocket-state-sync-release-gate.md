# P-19-25 — Shiprocket state-sync PR #354 release gate

| Field | Value |
|--------|--------|
| Global ID | **P-19-25** (continuation of **P-19-22**) |
| Cursor ledger ID | **`RadiumDesk-P-19-22`** (appended P-19-25) |
| Risk | **HIGH** |
| Timezone | **Asia/Kolkata (IST)** |
| Worktree | `.worktrees/p19-22-shiprocket-state` |
| Branch | `fix/p19-22-shiprocket-awb-label-state` |
| PR | [#354](https://github.com/radium-foundation/radium-desk/pull/354) → **`main`** |
| Primary WIP preserved | `feature/p14-38-desk-sole-payment-authority` @ `176104f38` |
| **Production deploy / Shiprocket mutation** | **NO — NOT PERFORMED** |

---

## Behavior matrix

| Item | Preserved | Extended | Changed | Removed |
|------|-----------|----------|---------|---------|
| Pre-AWB Ship & Generate Label | ✓ | | | |
| Pre-AWB courier / create / assign flows | ✓ | | | |
| Post-AWB CTA | | | → **Generate Label** | Misleading Ship & Generate Label after AWB |
| Label retry via orchestration | ✓ | ✓ (`canGenerateLabel` gate) | | |
| Idempotent create/AWB on orchestration | ✓ | | | |
| Desk pickup registration | ✓ | | | |
| Provider track after AWB | | ✓ read-only `trackByAwb` | | |
| Pickup Error visibility | | ✓ status + blocker | | |
| Request Pickup when Pickup Error | | | ✓ blocked UI + API | Unsafe retry |

---

## Focused test evidence (local)

Exit code **0** unless noted.

```bash
php -n vendor/bin/phpunit tests/Unit/Shipping/ShiprocketTrackingNormalizerTest.php \
  --filter status_id_13
# 1 test PASS

php -n vendor/bin/phpunit tests/Unit/HardwareFulfilment/HardwareFulfilmentOperationalClassifierTest.php \
  --filter 'awb_assigned_without_label|provider_pickup_error|test_existing_shipment_without_awb'
# 3 tests PASS

php -n vendor/bin/phpunit tests/Feature/HardwareFulfilment/HardwareFulfilmentShipAndLabelOrchestrationTest.php \
  --filter 'resume_at_label|label_failure|double_submit|idempotent'
# 3 tests PASS

php -n vendor/bin/phpunit tests/Feature/HardwareFulfilment/HardwareFulfilmentP5ShipmentTest.php \
  --filter 'awb_assignment_persists_read_only|awb_assignment_succeeds_when_read_only|pickup_request_is_blocked'
# 3 tests PASS
```

**Aggregate:** **10 tests, 10 PASS**, exit **0**.

**Pint:** `app/Services/HardwareFulfilment/Data/HardwareFulfilmentOperationalClassifier.php` — **fixed** (ordered imports).

**Full PHPUnit:** **NOT RUN** (per task policy).

**Vite/production build:** **NOT RUN** (PHP-only delta; no frontend bundle change).

---

## CI / branch protection

- **`main` branch protection:** **NOT CONFIGURED** (GitHub API 404) — **VERIFIED**.
- **PR #354 checks (pre-merge fix push):** `rip` **SUCCESS**, `release-rip` **SUCCESS**.
- **Required reviews:** none configured — **VERIFIED**.
- **Self-approval / bypass:** not used.

---

## Release preparation (pre-deploy stop)

| Gate | Status |
|------|--------|
| Proposed version | **`v4.1.98`** (CHANGELOG draft on branch) |
| Migration delta | **None** |
| Owner PRE-RIP on KVM | **NOT RUN** — **BLOCKED** until Owner authorizes deploy |
| KVM DB backup before deploy | **NOT RUN** |
| `release.json` update / `deskd` | **NOT RUN** |
| RBP2281 / RBP2310 repair | **NOT RUN** — await deploy + Owner label-only plan |

**Recovery identities (future repair only):**

- RBP2281 — shipment **579**, AWB `SF4531928884SPF`
- RBP2310 — shipment **590**, AWB `SF4531928841SPF`

---

## Remaining risks

1. **INFERRED:** Provider **Pickup Error** may block label generation at Shiprocket for Instant pickup — Desk cannot clear without provider ops.
2. **UNKNOWN:** Full CI / full PHPUnit on `main` post-merge until release pipeline runs.
3. Existing production rows lack `provider_track_*` until next AWB-assign path or explicit reconcile — **Owner** may run read-only track sync post-deploy.

---

## Owner approvals still required

- Production **deploy** of **v4.1.98**
- PRE-RIP / backup ceremony
- Label-only repair on RBP2281 / RBP2310