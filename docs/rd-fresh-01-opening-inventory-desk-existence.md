# RD-FRESH-01 — Opening inventory Desk existence gate (P-04-09-15)

**Project:** Radium Desk  
**Ledger:** RadiumDesk-P-04-09-15  
**Date:** 2026-09-04  
**Branch:** `feat/rd-fresh-01-inventory-pos`  
**HEAD:** `8a256f86dd143da4f3a3bf6bba70b2eb86740ca3`  
**Canvas:** [`rd-fresh-01-opening-inventory-desk-existence.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-RadiumWebsites-radium-desk/canvases/rd-fresh-01-opening-inventory-desk-existence.canvas.tsx)

**P-04-09-16 addendum (2026-09-04):** Owner-supplied `DELHI-RETAIL` and `MUMBAI` were inserted as the first two `inventory_branches` rows (active; GSTINs `07AAICP1128M1Z9` / `27AAICP1128M1Z7`). Addresses were not stored (no column). Opening apply was still **not** performed. See [`rd-fresh-01-pos-production-readiness.md`](rd-fresh-01-pos-production-readiness.md).

**Inventory apply/import was NOT performed.** No SKU, serial, or stock row was created or changed by P-04-09-15.

## Prompt ID

**RadiumDesk-P-04-09-15**

## Workbook identity (VERIFIED)

| Field | Value |
|-------|--------|
| Path | `/Users/ravi/Downloads/rd-fresh-01-opening-inventory-template.xlsx` |
| SHA-256 | `6048543e167f5ce7fb11265a4fa7f5c723dc26e6a37c89ca647280ebb81733f4` |
| Matches P-04-09-14 | Yes |
| Opening rows | 4,382 |
| Unique serials | 4,382 |
| SKU Master rows | 86 unique SKUs |
| Opening branch codes | `DELHI-RETAIL`, `MUMBAI` |

SHA matched before any database query.

## How Desk was queried

| Item | Value | Class |
|------|--------|-------|
| Host | `srv1910783` / `187.127.129.16` (`tools/config.sh`) | VERIFIED |
| App | `/var/www/radium-desk` · `APP_ENV=production` · `APP_URL=https://desk.radiumbox.com` | VERIFIED |
| Schema | `radium_desk` on `127.0.0.1:3306` | VERIFIED |
| Method | SSH `ravi@187.127.129.16` → `sudo mysql` **SELECT / SHOW only** | VERIFIED |
| Queried at | **2026-09-04 17:50:33** server local | VERIFIED |
| Local `.env` MySQL | Not used (loopback `radium_desk_local` still refused) | VERIFIED |
| AWS | Not contacted | VERIFIED |
| `radiumbox_prod` / Admin / rdservice.in | Not queried | VERIFIED |

`OpeningInventoryImportService::preview()` / `persistPreview()` / artisan `--apply` were **not** run. Those write batch/row tables. This gate used the committed workbook reader locally, then raw SELECT on production.

## Production inventory schema (VERIFIED)

These tables exist and are empty: `inventory_branches`, `inventory_products`, `inventory_product_variants`, `inventory_serials`, `inventory_stock_balances`, `inventory_sales`, `inventory_movements`.

Unique indexes live: `inventory_branches.code`, `inventory_products.sku`, `inventory_serials.serial_number`.

`inventory_opening_import_batches` and `inventory_opening_import_rows` **do not exist** on this production schema. Opening-import is on the feature branch and is not deployed.

## Branches

| Code | Status | Notes |
|------|--------|-------|
| DELHI-RETAIL | **MISSING** | Required by opening rows (4,342). `inventory_branches` has 0 rows. No `LIKE '%DELHI%'` match. |
| MUMBAI | **MISSING** | Required by opening rows (40). No `LIKE '%MUMBAI%'` match. |
| BIHAR | **MISSING** | On workbook Branches sheet only. Not used by opening rows. GSTIN was not read, copied, or invented. |
| DELHI-WH | **MISSING** | Same as BIHAR. GSTIN was not read, copied, or invented. |
| UTTAR PRADESH | **MISSING** | Workbook-only. Not used by opening rows. |

`inventory_branches` row count: **0**. `is_active` cannot be true for a missing row.

## SKUs

86 workbook SKUs were checked with `WHERE sku IN (…all 86…)` on `inventory_products` and `inventory_product_variants`.

| Metric | Count | Class |
|--------|------:|-------|
| Checked | 86 | VERIFIED |
| Found | 0 | VERIFIED |
| Missing | 86 | VERIFIED |
| Ambiguous / duplicate Desk SKU | 0 | VERIFIED (`inventory_products` empty; unique index on `sku`) |

Missing SKUs (all 86):

`RBAST300L1` `RBFM220UFP` `RBFM220CFP` `RBUGR86GPS` `RBUGR89GPS` `RBBPSR1200` `RBBPSR2600` `RBBMT20DI` `RBDKM3322W` `RBTOUCH510` `RBUAREUSIL` `RBEVOL250P` `RBEVOL300P` `RBEVO1300P` `RBEVOLISCK` `RBHYP2003T` `RBFUTFS80H` `RBFUTFS88H` `RBGDACTY84` `RBGEMACT30` `RBBU353GPS` `RBIMSOE3L1` `RBLOGC270W` `RBLOGM100R` `RBLOGMK220` `RBMATISXDI` `RBMFS100L0` `RBMFS110L1` `RBMFS500FP` `RBMIS100IR` `RBMORPHS60` `RBNEXT3023` `RBPROXKEYT` `RBPB1000L1` `RBHPRO20AP` `RBTMF20FPS` `RBTATVKGPS` `RBTRANSGPS` `RBBIOC600C` `RBBIOGPSG2` `RBMBAS50L1` `RBELIMOGPS` `RBFOXBSGPS` `RBMARC11L1` `RBIRIUNIV1` `RBIRIUNIV2` `RBTHUMBT1Z` `RBTHUMBT2Z` `RBFACEBXF1` `RBBPVRIDL1` `RBRAIVESBI` `RBMSOUSBCB` `RBMSOTYPEC` `RBMFSTYPEC` `RBMFSUSBCB` `RBFM220UCB` `RBFMTYPECB` `RBRAIVEBOB` `RBEMLKTMER` `RBEM600LBS` `RBEMDRLOCK` `RBADAPTBIO` `RBRFIDN200` `RBRFIDN100` `RBRFIDW200` `RBRFIDW100` `RBMBIOST2A` `RBBIOWERC1` `RBQRSCANER` `RBMR130MOX` `RBMR120MOX` `RBMBIOG1AD` `RBMBIO7SAD` `RBBIOWEBB2` `RBMBIOG2AD` `RBMBIOG3AD` `RBMBIO16AD` `RBMBIOS18A` `RBMBIOM18A` `RBBTMR104A` `RBBTMR103A` `RBMR110ATD` `RBBIOWEBB3` `RBDOORLOCK` `RBBIOTIME4` `RBMFSTABII`

Committed apply **creates** missing SKUs from SKU Master. This ticket did **not** create them. Missing is not a mapping collision.

## Serials

`inventory_serials` has **0** rows. Unique index `inventory_serials_serial_number_unique` is present. A table with zero rows cannot contain any of the 4,382 workbook serials.

| Metric | Count | Class |
|--------|------:|-------|
| Checked | 4,382 | VERIFIED |
| Already existing / conflicting | 0 | VERIFIED |
| Not existing | 4,382 | VERIFIED |
| Unknown | 0 | VERIFIED |

Workbook serials were preserved as text (including hex labels with the letter `E`). They were not written to Desk.

## Apply readiness

**NOT READY**

| Gate | Result |
|------|--------|
| DELHI-RETAIL | **MISSING** — BLOCKER |
| MUMBAI | **MISSING** — BLOCKER |
| SKU mapping | Unambiguous; 86 missing (apply would create) |
| Serial conflicts | None |
| Opening-import tables on production | **ABSENT** — BLOCKER if apply is to run on this host |
| persistPreview / apply this ticket | Not run |

## What must be resolved before a separate APPLY

Do not fix these in this ticket.

1. **Create active Desk branches** `DELHI-RETAIL` and `MUMBAI` with owner-supplied legal identity. Import never creates branches.
2. **Do not invent or copy GSTIN** onto workbook-blank `BIHAR` / `DELHI-WH`. Those codes are not required by the 4,382 opening rows.
3. **If apply targets this production host:** deploy the opening-import schema (`inventory_opening_import_batches`, `inventory_opening_import_rows`) and the import actor/permission. That is a separate deploy decision. This ticket did not migrate or deploy.
4. After branches exist, re-run a read-only existence gate if anything else is written to inventory first.
5. Authorized APPLY ticket only.

## Explicit statement

**Inventory apply/import was NOT performed.** No INSERT/UPDATE/DELETE/migration. Workbook was not modified. Application code was not changed. AWS, rdservice.in, and sibling projects were not contacted.
