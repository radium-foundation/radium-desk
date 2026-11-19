# P-19-22 — Shiprocket AWB / Pickup / Label state synchronization

**Global ID:** P-19-22  
**Cursor ledger ID:** `RadiumDesk-P-19-22`  
**Production (read-only):** v4.1.94 @ `b6b39f081` (KVM `deskvps` `/var/www/radium-desk`, deployed 2026-11-19 07:40 IST)  
**Fix branch:** `fix/p19-22-shiprocket-awb-label-state` (worktree `.worktrees/p19-22-shiprocket-state`, base `origin/main` @ `a016195a0`)  
**Production mutations performed:** **NO**

---

## Environment verification

| Item | Value | Confidence |
|------|--------|------------|
| Repository | `/Users/ravi/RadiumWebsites/radium-desk` | VERIFIED |
| Local HEAD (main checkout WIP preserved) | `feature/p14-38-desk-sole-payment-authority` @ `176104f38` | VERIFIED |
| Production release | v4.1.94 / `b6b39f081` | VERIFIED |
| Fix worktree | `.worktrees/p19-22-shiprocket-state` | VERIFIED |

---

## RBP2281 — root cause (HF 1530 / Shipment 579)

| Field | Production value |
|--------|------------------|
| Desk state | `awb_assigned` |
| AWB | `SF4531928884SPF` |
| Provider shipment / order | `1735660074` / `1740033690` |
| Courier | `252` Instant pickup - Shadowfax |
| Label URL | empty |
| `pickup_requested_at` | null |
| `provider_track_*` on row | empty (never synced) |

**Provider track (read-only `trackByAwb`, 2026-11-19):** `current_status_id` **13**, `current_status` **"Pickup Error"** — **VERIFIED**

**Desk UI before fix (computed on prod code):** `Label Pending`, next action **Ship & Generate Label** — **VERIFIED** (misleading; AWB already bound).

**Separate root cause narrative**

1. **VERIFIED:** Ship-and-label style flow created provider shipment and assigned AWB (events `09:48:08` created → `09:48:10` awb_assigned). No `hardware_label_generated` / label URL.
2. **INFERRED:** Label step failed or was never completed after AWB; orchestration left fulfilment in `awb_assigned` without surfacing provider movement in Desk.
3. **VERIFIED:** **"Pickup Error"** on RBP2281 is **Shiprocket provider track** (status 13), **not** Desk pickup registration (`pickup_requested_at` null). Instant pickup courier may advance/error on provider side independently of Desk `/courier/generate/pickup`.

---

## RBP2310 — root cause (HF 1540 / Shipment 590)

| Field | Production value |
|--------|------------------|
| Desk state | `awb_assigned` |
| AWB | `SF4531928841SPF` |
| Provider shipment / order | `1735816935` / `1740190595` |
| Courier | `252` Instant pickup - Shadowfax |
| Label URL | empty |
| `pickup_requested_at` | null |

**Provider track:** same pattern — status **13** **Pickup Error** — **VERIFIED**

**Desk UI before fix:** same as RBP2281 — **Ship & Generate Label** while AWB present — **VERIFIED**

**Separate root cause narrative**

1. **VERIFIED:** AWB assigned in Desk and in Shiprocket; label not persisted locally.
2. **VERIFIED:** Operator-facing CTA does not distinguish **label retry** from **full ship orchestration** (`canShipAndGenerateLabel` treated AWB-without-label as resumable ship-and-label).
3. **NOT** “AWB missing in Desk” — persistence of AWB is correct; desync is **label + provider track + CTA semantics**.

---

## Shared root cause

| Defect | Evidence | Confidence |
|--------|----------|------------|
| **Orchestration / readiness conflation** — AWB-without-label counted toward `canShipAndGenerateLabel`, so classifier shows **Ship & Generate Label** after AWB | `HardwareShipmentEligibility::$canResumeShipAndLabel` included `awbReady && labelUrl === null`; prod inspect for both orders | VERIFIED |
| **Provider track not persisted after AWB** — Pickup Error invisible in Desk | `shipments.provider_track_*` empty; read-only track shows Pickup Error | VERIFIED |
| **Pickup vs label vs AWB not surfaced** — provider Pickup Error (id 13) ≠ Desk pickup requested | `pickup_requested_at` null; track id 13 | VERIFIED |
| **Backend idempotency on re-click** — orchestration skips create/AWB when bound (tests `test_ship_and_label_resumes_at_label_when_awb_exists`) | Code + tests | VERIFIED (reduces duplicate AWB risk; UI still misleading) |

---

## Code fix (minimal)

1. **Eligibility:** Remove AWB-without-label from `canShipAndGenerateLabel` resume; keep label retry on `canGenerateLabel` / orchestration gate `canShipAndGenerateLabel || canGenerateLabel`.
2. **Classifier:** When AWB filled and label missing → **Generate Label** (surface **Pickup Error** status when `provider_track_status` says so).
3. **AWB bind:** Best-effort read-only `trackByAwb` after AWB assign; persist `provider_track_status` / normalized (no pickup API call).
4. **Normalizer:** Detect status id 13 / “Pickup Error”; eligibility blocker when label still missing.

---

## Tests run (local, mocked provider)

- `ShiprocketTrackingNormalizerTest::test_status_id_13_and_pickup_error_label_are_detected`
- `HardwareFulfilmentOperationalClassifierTest` (AWB → Generate Label; Pickup Error status label)
- `HardwareFulfilmentShipAndLabelOrchestrationTest` (`resume_at_label`, `label_failure`, `double_submit`)

---

## Safe production repair plan (Owner approval required — **not executed**)

**For RBP2281 / RBP2310 after fix deploy:**

1. **Read-only** reconcile provider track per fulfilment (no pickup API): persist track from `trackByAwb` only — or run existing reconcile flows that use track-only paths documented in P-18-09-20 class fixes.
2. **Generate label** via Desk **Generate Label** (or idempotent ship-and-label that skips create/AWB) — **not** a second shipment create.
3. **Do not** call **Request Pickup** while provider shows **Pickup Error** until Shiprocket/Shadowfax clears status 13 (instant pickup ops ticket). Correlation: ext shipment `1735660074` / `1735816935`, AWBs above.
4. **Do not** reassign courier or allocate second AWB.

**Production actions taken in this task:** **NO**

---

## Git / PR

- Commit/push/PR on `fix/p19-22-shiprocket-awb-label-state` (see PR link after push).
- **No deploy.**