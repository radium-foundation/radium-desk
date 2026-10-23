# Runbook: RadiumBox hardware product SKU mapping (Radium Desk)

Operational workflow for mapping **radiumbox.com** Box `model_id` values to Desk inventory SKUs via `channel_sku_maps`. Use this runbook for future mapping tasks; adapt the **single Cursor prompt** template at the end.

**Related policy:** [`docs/release-workflow.md`](../release-workflow.md), [`docs/rip-release-checkpoint.md`](../rip-release-checkpoint.md), [`docs/cursor-prompt-ledger.md`](../cursor-prompt-ledger.md).

---

## Scope

| In scope | Out of scope (unless separately authorized) |
|----------|-----------------------------------------------|
| Desk `config/hardware_fulfilment.php` seed definitions | RadiumBox.com catalog edits |
| `desk:seed-radiumbox-hardware-sku-maps` dry-run / `--apply` | Serial allocation, stock moves, shipping |
| Tests + CI for mapping resolution | Other channels (unless a different runbook exists) |
| KVM deploy when config changes require production code | Wallet, refunds, unrelated Desk features |

Deploying configuration **does not** insert `channel_sku_maps` rows. Production rows are created only by **`--apply`** (Owner-approved) or pre-existing data.

---

## Source of truth (verified in code)

| Layer | Location | Behavior |
|-------|----------|----------|
| **Seed definition** | `config/hardware_fulfilment.php` → `radiumbox_com.sku_maps[]` | Each row: `model_id`, `channel_sku`, `catalog_sku`, `desk_sku`, optional `notes` |
| **Persistence** | `channel_sku_maps` table | Unique per `channel` + `model_id`; channel enum `radiumbox_com` |
| **Resolution** | `App\Services\HardwareFulfilment\HardwareSkuMapService` | Runtime lookup for fulfilment |
| **Production apply** | `php artisan desk:seed-radiumbox-hardware-sku-maps` | Exactly one of `--dry-run` or `--apply`; never scheduled |
| **Queue UX** | `HardwareFulfilmentOperationalClassifier` + `HardwareShipmentEligibility` | e.g. **Product mapping required** vs **Allocate Serial** |

Regression tests (run locally / CI):

- `tests/Feature/HardwareFulfilment/RadiumboxHardwareSkuMapResolutionTest.php`
- `tests/Feature/Console/SeedRadiumboxHardwareSkuMapsCommandTest.php`
- Workflow: `.github/workflows/hardware-sku-map-rip.yml`

---

## 1. Input requirements (gather before any change)

Collect and record in the task / ledger:

| Input | Purpose |
|-------|---------|
| **Order / reference** | e.g. blocked hardware fulfilment `source_id` (read-only verification) |
| **Sales channel** | Usually `radiumbox_com` (`StatutoryInvoiceChannel::RadiumBoxCom`) |
| **Channel `model_id`** | Box hardware model id on the order line |
| **Catalog SKU** | Box catalog / PDP SKU string (evidence from Box or order payload) |
| **Desk SKU** | Target `inventory_products.sku` |
| **Inventory product ID** | Confirm on production before apply (IDs differ per environment) |
| **Evidence** | Owner approval, screenshots, Box model_id, prior audits |

**Do not** treat example values from this runbook as defaults—re-verify every identifier on the target environment.

---

## 2. Repository verification

1. **Repository:** `radium-desk` — `git@github.com:radium-foundation/radium-desk.git`.
2. **Avoid** the primary clone when it holds unrelated WIP (e.g. wallet/refund). Prefer an isolated **git worktree** under `.worktrees/`.
3. Record: branch, `HEAD`, remote, clean/dirty status.
4. Read `config/hardware_fulfilment.php`, `SeedRadiumboxHardwareSkuMapsCommand`, and existing tests.
5. Confirm whether the mapping already exists in config, in DB only, or neither.

**Not every mapping needs a code change:** if config already contains the row and production only lacks the DB row, the task may be **apply-only** after deploy.

---

## 3. Mapping discovery (read-only first)

### Production (KVM)

- **App root:** `/var/www/radium-desk` (see `tools/config.sh`).
- **Release identity:** `storage/app/private/release.json` (`version`, `tag`, `build`) — never `deployed-commit.txt`.
- **Health:** `https://desk.radiumbox.com/up` (HTTP 200).

Example read-only checks (run from app root on server; sanitize output in reports):

```bash
php artisan tinker --execute="
echo json_encode(json_decode(file_get_contents('storage/app/private/release.json'), true), JSON_PRETTY_PRINT);
"

php artisan tinker --execute="
\$mid = 1503; // replace per task
echo json_encode(DB::table('channel_sku_maps')->where('channel','radiumbox_com')->where('model_id', \$mid)->get(), JSON_PRETTY_PRINT);
"

php artisan tinker --execute="
\$sku = 'RBPRAGATI2'; // replace per task
echo json_encode(DB::table('inventory_products')->where('sku', \$sku)->first(), JSON_PRETTY_PRINT);
"
```

Inspect hardware fulfilment state by fulfilment id or `source_id` (column names vary—use schema-aware queries).

### Pair with Box (read-only)

When Desk mapping depends on Box `model_id` / catalog SKU, confirm on **radiumbox.com** (or paired audit) without mutating Box unless that project is explicitly in scope.

---

## 4. Mapping design

Document the intended row:

| Field | Rule |
|-------|------|
| `channel` | `radiumbox_com` |
| `model_id` | Integer Box model id (primary key for map) |
| `channel_sku` | Wire value on channel (often same as `model_id` string) |
| `catalog_sku` | Human/catalog reference |
| `inventory_product_id` | Resolved from `desk_sku` at seed time |
| `desk_sku` | Must exist in `inventory_products`; command does **not** create products |

**Check for:**

- Duplicate `model_id` or conflicting `inventory_product_id`
- Inactive or wrong serialized flag on inventory product
- Variant confusion (e.g. Regular vs Economy model ids)
- Orders already in flight—mapping fixes **Product mapping required**, not downstream steps

---

## 5. Implementation (smallest scoped change)

1. Add or update **one** entry in `hardware_fulfilment.radiumbox_com.sku_maps` (or fix an error).
2. Add/adjust PHPUnit cases mirroring the order journey (see `RadiumboxHardwareSkuMapResolutionTest`).
3. Run focused tests and path-scoped CI workflow locally when possible.
4. Update `CHANGELOG.md` when the change ships in a **versioned release**.
5. Open PR; do not merge/deploy without Owner gates.

**Backward compatibility:** seed command only **creates** missing rows; existing correct rows stay `unchanged`. Conflicting `model_id` → different product **fails validation** (no silent overwrite).

---

## 6. Dry-run gate (mandatory before `--apply`)

On **production** (or staging with same config if explicitly authorized):

```bash
cd /var/www/radium-desk
/usr/local/lsws/lsphp84/bin/php artisan desk:seed-radiumbox-hardware-sku-maps --dry-run
```

Inspect JSON output:

- For each row, `action` is `unchanged` or `create` (no updates/deletes in current command).
- **Stop** if any `create`/`update`/`delete` is outside the **single Owner-approved** mapping.
- **Stop** if `desk_sku` product is missing or validation errors appear.

Re-run dry-run after every deploy that changes `hardware_fulfilment.php`.

---

## 7. Release workflow (separate steps)

Each step needs explicit Owner authorization unless the task text grants it.

| Step | Notes |
|------|--------|
| **COMMIT** | Scoped files only |
| **PUSH / PR** | CI: `hardware-sku-map-rip`, `release-rip`, `rip` as applicable |
| **MERGE** | Record merge SHA |
| **TAG** | Semver tag must match `CHANGELOG.md`; **do not move** existing tags |
| **DEPLOY** | KVM: `DEPLOY_MODE=kvm`, `desk deploy` from clean tree at **exact** release tag (`deploy-kvm.sh` validates tag) |
| **RIP pre-deploy** | `RIP_REQUIRE_PRE_DEPLOY_CHECKPOINT=1`, `RIP_PRE_DEPLOY_CHECKPOINT_ID=…`; verify with `rip:verify-checkpoint` + `rip:verify-pre-deploy-gate` |
| **Post-deploy RIP** | `capture-release-recovery-checkpoint.sh` for deployed build |
| **VERIFY** | `release.json`, smoke, dry-run |

If a fix lands **after** a release tag (e.g. gate fix after `v4.1.34`), use a **new** tag (e.g. `v4.1.35`) per release policy—do not retag.

Use isolated worktree: `DEPLOY_KVM_PROJECT_ROOT`, `DEPLOY_KVM_BRANCH_OVERRIDE` when branch name differs from `main`.

---

## 8. Production data mutation (`--apply`)

**Requires explicit Owner approval** for the exact mapping row.

1. Confirm production `release.json` build includes the config entry (deployed code).
2. Confirm recovery checkpoint for current production (verify, do not overwrite).
3. Dry-run shows **only** intended `create` (or row already `unchanged` if idempotent re-run).
4. Run:

```bash
/usr/local/lsws/lsphp84/bin/php artisan desk:seed-radiumbox-hardware-sku-maps --apply
```

5. Capture exit code and JSON; on failure, **read-only** state inspection before retry.
6. **No** manual SQL, no other seed commands, no unrelated rows.

---

## 9. Post-apply verification (read-only)

1. **Exactly one** `channel_sku_maps` row for `channel` + `model_id`.
2. `HardwareSkuMapService::requireProduct(channel, model_id)` → expected `desk_sku` and product id.
3. Confirm intentionally unmapped variants still fail resolution.
4. **Order queue:** load `HardwareFulfilment` with relations; use `HardwareShipmentEligibility::inspect()` and `HardwareFulfilmentOperationalClassifier::fromFulfilment()`:

   - Unmapped: operator status **Product mapping required**, next action **View**.
   - Mapped serialized product: e.g. **Awaiting Serial**, next action **Allocate Serial**.

5. **Do not** allocate serials, change fulfilment state, or ship.

---

## 10. Stop conditions

Stop and report to Owner if:

- Wrong repository, host, or app root
- Ambiguous SKU / model_id / variant
- Duplicate or conflicting map
- Dry-run proposes extra mutations
- Tests or CI fail
- RIP / deploy gate fails
- Missing deploy or `--apply` authorization
- Production state differs from expected after apply
- Classifier still shows mapping required after apply (investigate line `model_id`, config deploy, cache)

---

## 11. Completion report template

Every mapping task should end with:

1. ChatGPT prompt ID + **Cursor ledger ID** (append-only row in `docs/cursor-prompt-ledger.md`).
2. Production `release.json` version/build.
3. RIP checkpoint ids verified (not secrets).
4. Dry-run summary and `--apply` result.
5. Before/after `channel_sku_maps` for affected `model_id`s.
6. Target order: fulfilment id, state, `operatorStatus()`, `nextAction`.
7. Tests run and results.
8. Release classification (`deployed` / `smoke-verified` / `known-good`) — do not overstate.
9. Rollback: redeploy prior tag vs map row removal (data).
10. For each action not taken: **`NO — Not performed.`**

---

## 12. Safety boundaries

- No serial allocation, shipment, or fulfilment mutations unless **separately** authorized.
- Other RadiumWebsites repos: **read-only** unless the task includes them.
- No secrets, `.env` contents, or customer PII in reports.
- One prompt does **not** waive dry-run, RIP, deploy, or `--apply` gates.

---

## Reusable checklist

```
[ ] Inputs + Owner approval documented
[ ] Worktree / branch / HEAD verified
[ ] Config + tests inspected
[ ] Read-only prod: release.json, maps, product, order/HF state
[ ] Design reviewed (variants, serialized, conflicts)
[ ] Code/test changes (if needed) + CI
[ ] PR merged (if needed)
[ ] CHANGELOG + tag + deploy (if needed) + RIP gates
[ ] Production dry-run — only approved creates
[ ] Owner-approved --apply
[ ] Post-apply map + classifier verification
[ ] Ledger appended
[ ] Completion report issued
```

---

## Suggested single Cursor prompt structure

Copy and fill placeholders. **Skip stages** that do not apply; **stop** on any failed gate.

```markdown
# P-XX-YY — RadiumBox hardware SKU mapping: <short title>

**Project:** Radium Desk  
**Repository:** `/Users/ravi/RadiumWebsites/radium-desk`  
**Runbook:** `docs/runbooks/product-sku-mapping.md`

## Authorized scope
- [ ] Code/config change only | Deploy only | Apply-only | Full pipeline
- Explicit Owner authorization for: merge / deploy / `--apply` (list which)

## Mapping under review
- Channel: radiumbox_com
- model_id: <verify>
- catalog_sku: <verify>
- desk_sku: <verify>
- inventory_product_id: <verify on prod>
- Order/reference for verification: <verify>

## Workflow (stop at each gate)

### VERIFY
Repository, worktree, branch, HEAD, remote; production target and release.json.

### INSPECT
Read-only: channel_sku_maps, inventory_products, hardware fulfilment for reference order.

### ANALYZE
Conflicts, variants, serialized flag, deploy vs apply-only path.

### MODIFY (if needed)
config/hardware_fulfilment.php + tests; smallest diff.

### TEST
phpunit: RadiumboxHardwareSkuMapResolutionTest, SeedRadiumboxHardwareSkuMapsCommandTest.

### REVIEW
Full diff; no unrelated changes.

### COMMIT / PUSH / PR
Only if authorized.

### RELEASE
CHANGELOG, tag, merge policy; no tag moves.

### DEPLOY
KVM desk deploy, RIP pre-deploy gate; isolated worktree.

### VERIFY (production)
dry-run → optional --apply → map row + classifier next action (read-only).

## Completion report
Per runbook §11. Unperformed actions: `NO — Not performed.`
```

---

## Historical example only (Pragati Regular — **not defaults**)

Values below are **example-specific** from October 2026 work (**P-23-20** through **P-23-36**). Re-verify everything on the live system before reuse.

| Field | Example value |
|-------|----------------|
| Order / reference | `RBP1183` |
| Channel | `radiumbox_com` |
| Model ID | `1503` |
| Catalog SKU | `PBPPRAREG2` |
| Desk SKU | `RBPRAGATI2` |
| Inventory product ID | `96` (serialized, active on prod at time of apply) |
| Economy variant (intentionally unmapped) | Model `1502` / `PBPPRAECO2` |
| Config PR | #62; CHANGELOG **4.1.34**; deploy vehicle **v4.1.35** build `d6a46b00` (RIP gate fix #64) |
| Production map row after apply | `channel_sku_maps.id` **36** (example—ids are not stable across environments) |
| Hardware fulfilment | id **1231**, state `ready_for_fulfilment` |
| After apply classifier | `next_action` **Allocate Serial** (no serial allocated in that task) |
| Pre-deploy RIP (example) | `RIP-221026-1048-radium-desk-p22-16-driver-media-pre-34477be` |
| Post-deploy RIP (example) | `RIP-231026-1351-radium-desk-post-v4135-deploy-d6a46b0` |

**Lesson:** Deploy synced config; **dry-run** showed a single `create` for `1503`; **apply** inserted the row; queue moved from mapping blocked to **Allocate Serial**.
