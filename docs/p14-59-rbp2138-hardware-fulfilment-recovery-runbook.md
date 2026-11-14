# P-14-59 — RBP2138 hardware fulfilment recovery runbook (Owner-gated)

**Status:** Prepared for Owner approval only. **No production steps have been executed by automation.**

## Identities (production-verified P-14-57)

| Field | Value |
|--------|--------|
| Commerce order id | `22155` |
| Commerce order no | `CO-022155` |
| Box / source id | `RBP2138` |
| Desk support order id | `73007` |
| Payment reference (Cashfree) | `6998469575` |
| Hardware line | `model_id=946` (`RBMFS110L1`), qty `1` |
| Support serial (do **not** auto-allocate) | `11119634` |

## Preconditions (manual)

1. Owner written approval referencing **RadiumDesk-P-14-59**.
2. Production confirms: commerce **paid**, physical line present, **no** `hardware_fulfilments` row, handoff **2079** already delivered (no replay).
3. Hardware operations will **manually** confirm warehouse serial at allocation time — **do not** trust support-order serial alone.

## Step 1 — Record Owner authorization (Desk artisan, production)

```bash
cd /var/www/radium-desk
php artisan desk:authorize-existing-commerce-fulfilment RBP2138 CO-022155 \
  --actor="Owner:P-14-59" \
  --purpose="owner-authorized-existing-commerce-fulfilment"
```

Expect JSON `ok: true`. This writes `hardware_recovered_fulfilment_authorizations` only; it does **not** open HF or allocate serials.

## Step 2 — Open hardware fulfilment (no Box payload)

**Option A — Hardware dashboard:** Hardware workspace → locate **RBP2138** → **Open Fulfilment** (awaiting action).

**Option B — Isolated CLI (single id):**

```bash
php artisan desk:fulfil-hardware RBP2138 --step=ingest
```

Do **not** pass `--payload`. Re-running ingest is idempotent (one HF row).

## Step 3 — Serial allocation (human gate)

Use normal fulfilment UI allocation after ops confirms the correct unit serial in the warehouse. **Do not** script-assign `11119634`.

## Explicit non-actions

- **NO** channel ingest / Box handoff replay
- **NO** second commerce order
- **NO** automatic RBP auto-ingest policy change
- **NO** deploy implied by this runbook