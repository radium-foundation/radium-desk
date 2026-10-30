# P-30-39 — RBP1407 production execution package (Owner approval required)

**Not authorized for execution by this document alone.** Follow `docs/runbooks/product-sku-mapping.md` gates (deploy, RIP, dry-run, apply, verify).

## Phase 1 — Desk deploy + seed (mapping only)

1. Deploy `config/hardware_fulfilment.php` containing **model_id 1506** → `desk_sku` **RBBPSR1200** (and preserve all existing prod `sku_maps` rows).
2. `php artisan desk:seed-radiumbox-hardware-sku-maps --dry-run` — expect **one** `"action":"create"` for **model_id 1506** only.
3. Owner-approved `php artisan desk:seed-radiumbox-hardware-sku-maps --apply`.
4. Verify: `SELECT * FROM channel_sku_maps WHERE channel='radiumbox_com' AND model_id=1506;` → `inventory_product_id=6`, `catalog_sku='PBPSAMREG1'`.

## Phase 2 — Order line alignment (same maintenance window)

**Preconditions (read-only):** Box `order_details.id=731215` has `modelid=1505`, qty=2; Desk item `31354` has `model_id=1505`; HF `1271` `ready_for_fulfilment`, `serials_allocated_at` NULL.

```sql
-- Box (radiumbox_prod)
UPDATE order_details
SET modelid = 1506, updated_at = NOW()
WHERE id = 731215 AND orderid = 320409 AND modelid = 1505;

-- Desk (radium_desk)
UPDATE commerce_order_items
SET model_id = 1506,
    sku = '1506',
    catalog_sku = 'PBPSAMREG1',
    updated_at = NOW()
WHERE id = 31354 AND commerce_order_id = 15608 AND model_id = 1505;
```

**Do not** update `order_details.product_json`, replay handoff **1727**, allocate serials, or change payment totals.

## Rollback

```sql
UPDATE order_details SET modelid = 1505, updated_at = NOW()
WHERE id = 731215 AND orderid = 320409 AND modelid = 1506;

UPDATE commerce_order_items
SET model_id = 1505, sku = '1505', catalog_sku = NULL, updated_at = NOW()
WHERE id = 31354 AND commerce_order_id = 15608 AND model_id = 1506;

DELETE FROM channel_sku_maps
WHERE channel = 'radiumbox_com' AND model_id = 1506 AND inventory_product_id = 6;
-- capture inserted id before delete
```

## Phase 3 — Stock (separate Owner gate)

Serialized availability for `RBBPSR1200` must reach **≥2** before allocation. Mapping does not create stock.

## Git promotion and deploy safeguard (P-30-47)

Production already carries **`config/hardware_fulfilment.php` SHA-256 `e44492b40e295d12d101d81853b71adf62ab08926dae250b83553a49636cacde`** (model **1506** block) via P-30-42 overlay while **`release.json`** remained **v4.1.48** / build **`009d3e3c`**.

**Warning:** A KVM **`desk deploy`** from a Git tag that omits the model **1506** row will **remove** the live overlay and drop the seed definition (existing DB map row **37** would remain until manually removed, causing config/DB drift).

**Post-change recovery reference (do not replace):** **`RIP-301026-1054-radium-desk-post-p30-42-rbp1407-sku1506-009d3e3`**.

**Required checks after any future separately authorized Desk release deploy:**

1. On production: `sha256sum config/hardware_fulfilment.php` equals **`e44492b4…`** (or Git at deployed tag contains model **1506** → **`RBBPSR1200`**).
2. `php artisan desk:seed-radiumbox-hardware-sku-maps --dry-run` — model **1506** must report **`unchanged`** (map **37** already applied in P-30-42).
3. **Do not** run `--apply` for **1506** when dry-run shows **`unchanged`** unless Owner explicitly re-seeds after a verified map deletion.
