# RadiumDesk-P-07-09-142 — Frozen-seven READY stop

**Verdict:** STOPPED. No production mutation. No code change. No deploy.

Commerce identities CO-000755…761 are present and match the verified recovery map. Hardware Fulfilment rows do not exist. Desk still treats these seven source ids as unconditionally frozen. Box frozen-recovery authorization is **not** consulted by Desk fulfilment.

## Why READY was not executed

Desk `HardwareFulfilmentEligibility::FROZEN_SOURCE_IDS` is checked unconditionally in:

- `shouldOpenRecord()` — ingest will not open a new HF for these sources
- `assertIsolatedTarget()` — `desk:fulfil-hardware` refuses them
- `HardwareFulfilmentWorkflowService::transition()` / `markReady()` — state cannot change
- later serial / invoice / shipment / parcel / callback gates (not used here)

Box `desk_frozen_recovery_authorizations` lifts Box rebuild/deliver `frozen_hold` only. Desk has no equivalent table, command, or helper.

Isolated `--step=ready` dry-run for each of the seven failed closed with: no hardware fulfilment exists (ingest would require `--payload`, which would replay a Box handoff).

Creating HF by replaying the Box handoff, removing the frozen list, SQL state updates, or `--force` are all out of bounds.

## Production identities (read-only, 2026-09-09)

| RDE | Commerce | Paid | Payment ref (Box = Commerce) | Support CF id | Model / qty / HSN | Map | HF |
|---|---|---|---|---|---|---|---|
| RDE318388 | 755 / CO-000755 / ₹1999 | paid | 6842312867 | 51176 / 6421331170 | 1723 ×1 / 85269190 | inv 5 `RBUGR89GPS` | 0 |
| RDE318379 | 756 / CO-000756 / ₹3549 | paid | 6840825987 | 51111 / 6419934879 | 1006 ×1 / 84716050 | inv 30 `RBMIS100IR` | 0 |
| RDE318360 | 757 / CO-000757 / ₹8997 | paid | 6833166586 | 50935 / 6412545096 | 951 ×2 + 1006 ×1 / 84716050 | inv 22 `RBIMSOE3L1` + 30 | 0 |
| RDE318391 | 758 / CO-000758 / ₹2971 | paid | 6842851263 | 51192 / 6421837357 | 946 ×1 / 84716050 | inv 28 `RBMFS110L1` | 0 |
| RDE318382 | 759 / CO-000759 / ₹3049 | paid | 6841021980 | 51118 / 6420103589 | 951 ×1 / 84716050 | inv 22 `RBIMSOE3L1` | 0 |
| RDE318378 | 760 / CO-000760 / ₹4899 | paid | 6840796813 | 51107 / 6419889658 | 930 ×1 / 84716050 | inv 17 `RBFUTFS80H` | 0 |
| RDE318367 | 761 / CO-000761 / ₹2549 | paid | 6834168621 | 51001 / 6413475671 | 946 ×1 / 84716050 | inv 28 `RBMFS110L1` | 0 |

All seven: `ordered_at` on/after cutoff 2026-09-05 IST; wallet null; no split tender; payment refs unique; serials 0; invoices none; shipments none; AWB none; exactly one Commerce; ingest attempts accepted (HF skipped because frozen). Box handoffs 8/11/16/17/19/26/27 `delivered` with matching `desk_order_no`. Box auth rows 1–7 `authorized` / purpose `owner-authorized-hardware-recovery` (`radiumbox.com-P-08-09-33`). `isAuthorized(handoff)` true. Freeze lists on Box and Desk unchanged.

**SKU note:** Desk stores commerce `sku` as the model id (`951`, `1006`, …). Owner `channel_sku_maps` are complete. Prompt-table Box SKUs (`PIDMORE3L1`, `PMTMIS100Z`, `PMTMFS110Z`) are not the Desk sku column. RDE318388 prompt-table SKU `PIDMORE3L1` does **not** match production product (model `1723` UGR GPS / `RBUGR89GPS`). Canonical identity is model id + map, not that prompt SKU string.

`commerce_orders.support_order_id` is null on all seven. HF open via `ensureIngested` would correlate the existing support `orders.id`.

Exclusions untouched: RDE318438 / RIN3512344 / RIN3512331 / RDE255714 / RDE313554 still have no Commerce/HF. RDE318400 remains CO-000740 / HF14 `ingested`.

---

# Implementation prompt (do not run inside the operational READY prompt)

**Recommended model:** Grok 4.6 or Composer 2.5

## Objective

Add the missing Desk distinction between:

1. historically frozen source recovery (still protected), and
2. already-authorized, already-recovered Desk Commerce orders that may enter the normal Hardware Fulfilment lifecycle.

Then stop. Do **not** READY the seven in the same prompt as the code change. A later isolated operational prompt performs ingest-from-existing-commerce + `--step=ready`.

## Non-goals / safety

- Do not remove `FROZEN_SOURCE_IDS`.
- Do not weaken `HOLD_SOURCE_IDS` (`RDE318438`, `RDE255714`, `RDE313554`).
- Do not authorize `RDE318400`, `RIN3512344`, `RIN3512331`, or any other source.
- Do not read Box `desk_frozen_recovery_authorizations` over the network from Desk.
- Do not modify Box authorization rows.
- Do not replay Box handoffs or create a second Commerce order.
- Do not allocate serials, invoice, parcel, or call Shiprocket.
- Do not use `--force` or SQL state updates.

## Required behaviour

### 1. Desk authorization record (fail closed)

Add a Desk-local authorization mechanism, analogous in *intent* to Box `DeskFrozenRecoveryAuthorization`, but scoped to fulfilment:

- Exact allowlist of authorizable pairs only:

```text
RDE318360, RDE318367, RDE318378, RDE318379, RDE318382, RDE318388, RDE318391
```

- Persist one row per source: `source_id`, `commerce_order_id`, `commerce_order_no`, `purpose`, `status=authorized`, actor, timestamps.
- Artisan command records authorization only after verifying current production/test Commerce identity (exactly one paid Commerce, expected `CO-000755`…`761` map, physical lines present). It must not open HF or change state.
- Unknown / HOLD / blocked-until sources fail closed.
- Duplicate conflicting rows fail closed.

### 2. Single eligibility helper

Replace unconditional frozen refusals used by fulfilment **open + READY** (and the same helper everywhere frozen is checked for these sources, so later serial/invoice/ship stay consistent):

```text
isFrozenForFulfilment(sourceId) =
  isFrozenSourceId(sourceId) AND NOT isAuthorizedRecoveredFulfilment(sourceId)
```

`isAuthorizedRecoveredFulfilment` is true only when the Desk authorization row matches the live unique Commerce identity.

Keep `isFrozenSourceId()` as the historical list for classifiers/queue labels if those must still *name* the seven as recovered-frozen rather than ordinary work. Do not silently drop them from owner-review counts without an explicit product decision; default: classifiers may still tag them frozen until HF exists, but they must no longer be *refused* by ingest/READY after Desk authorization.

### 3. Open HF from existing Commerce (no Box replay)

Today `shouldOpenRecord` returns false for frozen, so accepted ingest created Commerce and skipped HF. Isolated `--step=ingest` requires `--payload`.

Add an authorized path:

```text
desk:fulfil-hardware {RDE} --step=ingest
```

When:

- exactly one matching paid Commerce already exists,
- Desk frozen-fulfilment authorization exists,
- HF count is 0,

then open **exactly one** HF from the persisted Commerce (+ items), via `HardwareFulfilmentFoundationService::ensureIngested` using a request built from Desk Commerce — not from a new Box POST.

Refuse `--payload` on these seven (replay forbidden). Refuse if Commerce missing, duplicate, unpaid, or identity mismatch. Preserve RDE318360 both physical lines (951×2 and 1006×1); do not split or collapse.

Idempotent: second ingest returns the existing HF.

### 4. READY remains the existing state machine

After HF exists at `ingested`, `desk:fulfil-hardware {id} --step=ready` must pass `assertIsolatedTarget` and `markReady()` for authorized recovered sources only. Unauthorised frozen sources still throw the current frozen message.

Do not bypass cutoff, paid, or physical-line gates. These seven already have `ordered_at` on/after 2026-09-05 IST.

### 5. Tests

- Unauthorised frozen seven: still cannot open HF or READY.
- Authorised recovered + existing Commerce: ingest-from-commerce opens one HF; ready → `ready_for_fulfilment`; serials 0.
- RDE318360 keeps two physical lines / qty 2+1.
- HOLD `RDE318438` / `RDE255714` / `RDE313554` still refused.
- `RDE318400` still blocked-until-authorized.
- Frozen list constant still contains the seven.
- No Box HTTP in Desk tests.

### 6. Git / deploy

Inspect repo, implement, test, commit, push `origin` (`git@github.com:radium-foundation/radium-desk.git`), deploy via the documented overlay. Do not touch MakeLinkIT.

After deploy, **stop**. Do not READY production orders in the code prompt.

## Follow-on operational prompt (separate)

After the gate is live: one-at-a-time, dry-run then `desk:fulfil-hardware {RDE} --step=ingest` (no payload) then `--step=ready`. Endpoint `READY_FOR_FULFILMENT`. Serials 0. No invoice/ship. RDE318360 last or separately. Exclusions unchanged.
