# RD-FRESH-01 — Opening inventory field matrix

**Project:** Radium Desk  
**Ticket:** RadiumDesk-P-01-09-08  
**Date:** 2026-09-01  
**Type:** Read-only field investigation + empty opening-inventory template. **No inventory migration.**  
**Canvas:** [`rd-fresh-01-inventory-opening-field-matrix.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-RadiumWebsites-radium-desk/canvases/rd-fresh-01-inventory-opening-field-matrix.canvas.tsx)  
**Prior SQL assessment:** [`rd-fresh-01-radiumbox-inventory-investigation.md`](rd-fresh-01-radiumbox-inventory-investigation.md) (P-01-09-06)  
**Desk foundation:** [`rd-fresh-01-inventory-pos-foundation.md`](rd-fresh-01-inventory-pos-foundation.md)  
**Workbook (not in Git):** `storage/app/private/inventory-opening/rd-fresh-01-opening-inventory-template.xlsx`

**Business decision:** old Admin/POS stock is a **field reference only**. Physical count on the opening date becomes Desk opening inventory. Do **not** copy `product_stock` serials, quantities, or balances.

**Classification:** **VERIFIED** = Admin PHP/views this ticket, or SQL/DESCRIBE from P-01-09-06. **INFERRED** = consistent with that evidence. **UNKNOWN** = not inspected or owner decision.

---

## Verdict

Old warehouse stock is **one serial row per physical unit** on `product_stock`, keyed by model `products.id` (`attribute_id=5`), located by `radium_branch.slug`. Quantity is a **row count**, not a qty column. Condition/status is a **single overloaded** `product_stock.status` (New / Sold / Return / Replaced / Renew / Old / Damage) that POS often **does not update on sale**. Cost is **not per serial**. GST for POS is **model `gst_percentage` on exclusive `selling_price`**.

Day-1 Desk opening must capture: **date, branch, SKU, serial (if serialized), quantity, condition (New / Used / Refurbished only), stock status (Available / Damaged), optional unit cost and selling price, GST% / HSN from SKU master, remarks, counted-by**.

Do **not** carry storefront/SEO fields, clone-on-move history, `product_stock_report`, invoice sequences, wallet/shipping/TCS, or `radium_branch.mycafe_password`.

**Old inventory data imported:** **NO**.  
**`radiumbox_prod` modified:** **NO**.  
**Desk production modified:** **NO**.

---

## Safety

| System | This ticket |
|--------|-------------|
| Admin tree `/Users/ravi/RadiumWebsites/Admin` | Read-only |
| `radiumbox_prod` | Not written (SQL from P-01-09-06 reused; no new production queries required for field names) |
| RadiumBox storefront | Not modified |
| Desk production | Not modified; feature branch not deployed |
| DNS / Cloudflare / payments / BonVoice | Not touched |

---

## A. Old tables inspected

| Table | Role in inventory/POS | Class |
|-------|----------------------|-------|
| `products` | Catalog: main listing + models + RD/AMC/OTG/add-ons | VERIFIED model fillable + P-01-09-06 DESCRIBE |
| `product_stock` | One serial **instance** per row; clone on transfer | VERIFIED `Stock` model + `StockController` |
| `product_stock_log` | Sold / Move / Rollback / Return / Replaced / Damage / Renew | VERIFIED inserts |
| `product_stock_report` | Cached qty by product+branch; **stale** | VERIFIED cron; **do not use** |
| `radium_branch` | Location slug, GST, address, invoice/RD sequences, **secret password** | VERIFIED |
| `categories` / `subcategories` / `attributes` / `brands` | Catalog taxonomy; `attribute_id=5` = Model (stocked) | VERIFIED |
| `product_images` / `product_category` | Media / unused extra category | VERIFIED unused for POS stock |
| `supplier_purchase` / `supplier_purchase_product` | PO header; Excel stock-in requires existing `purchase_id` | VERIFIED |
| `orders` / `order_details` | POS creates `ordertype=POS` **before** serials; lines hold model + RD/AMC/OTG ids; serial JSON in `label` | VERIFIED `PosController` |
| `orders_payment` / `users_wallet` | POS payment + optional wallet debit | VERIFIED |
| `invoice` / `credit_note` | GST invoice / credit note; credit note **has no serial column** | VERIFIED P-01-09-06 |
| `users` / `users_address` | POS customer + GST/address | VERIFIED POS form |
| `model_list` | Unrelated to `product_stock` models | VERIFIED P-01-09-06 |

**Absent (VERIFIED):** reservation/hold table; unit-of-measure on `products`; per-serial cost on `product_stock`; in-transit hop; certificate-type / token-type stock tables; package/bundle stock SKU.

---

## B. Old POS workflow (operator path)

Source: `Admin/app/Http/Controllers/Order/Pos/PosController.php`, `SerialUpdateController.php`, `PaymentStatusController.php`, `EditPosController.php`, views under `Admin/resources/views/Order/pos/`.

| Step | What the old POS actually does | Inventory fields used |
|------|-------------------------------|------------------------|
| Product search | Dropdown of `products` where `is_main=1` and `status=1` | Parent listing name, not stock SKU |
| SKU / model | Second select loads children (`attribute_id=5` models) | `modelid` → stocked `products.id` |
| Variant | **No separate variant table.** Model row **is** the SKU. | — |
| RD / AMC / OTG | Optional additional product (`attribute_id` 1 / 2 / 4) as extra **order lines**, not warehouse serials | `rdserviceid`, `amcid`, `otgid` |
| Branch | `radium_branch.slug` on the order | Selling location |
| Availability | Serial assign looks up `product_stock` `label` + `is_sold='No'` + `product_id=modelid` | Serial + sold flag |
| New / used / refurbished | **Not selected on POS.** Cron later treats Replaced/Renew/Old/Return as refurbished | `product_stock.status` |
| Cart | Qty, exclusive selling price, GST% from **model** | `selling_price`, `gst_percentage`, `hsn_code` |
| Customer | Search email/phone; address + GST | `users`, `users_address.gst_no` — **not stock** |
| Discount | Header `discount_cost` | Order, not inventory |
| GST | Line tax from model GST%; shipping uses **18% reverse GST** | Model GST%; shipping is POS-only |
| Payment | Bank/cash/cheque/credit; optional wallet | `payment_type`, `users_wallet` |
| Invoice | Separate invoice generation / e-invoice on `orders` | Not a stock field |
| Sale complete | Order saved first; serials assigned later; `is_sold='Yes'`, `credit=0`; **status often left `New`** | `is_sold` / `credit` |
| Stock deduction | No qty column — mark serial sold | Serial row |
| Serial status | Log `Sold`; optional clone if `is_move` | `product_stock_log` |
| Cancel / rollback | Log `Rollback`; restock | Log + `is_sold` |
| Return / replace | Manual stock status form: Return / Replaced / Renew / Old / Damage | `product_stock.status` |
| Transfer | `Move()`: mark old sold, **insert new row** at destination with `status='New'` | Clone-on-move |
| Reporting | Inventory screen reads **`product_stock_report`** (stale) | Do not use for opening |

Desk POS already: search SKU/serial, cart, customer, header discount, line GST from `inventory_products.gst_percentage`, complete in one transaction, unique serial relocate (not clone). Desk does **not** clone shipping 18%, TCS, wallet, or RD/AMC/OTG add-on lines.

---

## C. Admin screens/forms (inventory)

| Screen | Fields operators enter | Validation (as coded) |
|--------|------------------------|-----------------------|
| Product add/edit | Entire `products` fillable (SEO, drivers, packing/PG/margin, websites, …) | Catalog, not warehouse count |
| Stock paste (`StockController::store`) | Branch slug, status, comma serials | Unique check = **`label` + `product_id` only** (not global) |
| Stock Excel import | File column `serial_no`; branch, status, **purchase_id required and must exist** in `supplier_purchase` | Same non-global unique |
| Stock status modal | `status`, `is_sold`, description | Damage forces sold |
| Move stock | Destination branch + serial list | Finds unsold `label` (not product-scoped); clones row |
| POS | Customer, address/GST, branch, main product, model, optional add-ons, shipping, discount, payment | Order before serials |

---

## D. Serial-number lifecycle

| Event | Old behaviour | Opening implication |
|-------|---------------|---------------------|
| Receiving | Insert `product_stock` (`is_sold='No'`, `credit=1`, `status` from form, usually New) | One physical unit → one opening row |
| Available | `is_sold='No'` | Opening **Stock Status = Available** |
| Branch transfer | Clone: old sold, new row at dest `status='New'` | Desk relocates one row — **do not import clones** |
| Sale | `is_sold='Yes'`; status often still New | Do **not** put sold units on opening sheet |
| Cancellation | Rollback log; restock | History only — DROP |
| Return | Status Return; may be unsold (19 available Return rows in P-01-09-06) | Map condition **Used** or **Refurbished** (owner: Refurbished if returned-to-sell) |
| Replacement | Status Replaced | Condition **Refurbished** |
| Refurbishing | Cron: Replaced / Renew / Old / Return = refurbished bucket | Condition **Refurbished** (Old → **Used** if still original device, **UNKNOWN**) |
| Damaged | Status Damage **and** `is_sold='Yes'` (unsellable) | Stock Status **Damaged**, not a fourth condition |
| Duplicate serials | Allowed across products/branches because unique is label+product | Desk + this template: **global unique**; flag duplicates |
| Ownership | Branch slug on the row | Branch Code |
| History | `product_stock_log` | DROP from opening (empty Desk ledger) |

---

## E. Branch inventory

Old system has **one location table** (`radium_branch`). There is **no warehouse vs retail column**. Distinction is **by slug**: `delhi_wharehouse` vs `radium_delhi` / `radium_bihar` / `radium_mumbai`. `radium_up` and `radium_chennai` had **zero** live stock (P-01-09-06).

Available quantity = count of unsold `product_stock` rows at that slug. Transfers are immediate clones; no in-transit.

Opening template uses **new** branch codes (`DELHI-RETAIL`, `DELHI-WH`, `BIHAR`, `MUMBAI`) plus **Location Type** Retail / Warehouse / Office. Suggested GSTINs are the **public GSTIN values already printed on Admin invoices**, not secrets. Bihar GSTIN was a **copy of Delhi** in Admin — left blank on the template for confirmation.

---

## F. Product structure (verified, not assumed)

| Concept | Exists in old inventory? | Evidence |
|---------|--------------------------|----------|
| Product (listing) | Yes — `is_main=1` | POS first dropdown |
| SKU / model | Yes — child `attribute_id=5`; **this is what is stocked** | 53 available SKUs all models |
| Variant table | **No** | No `product_variants` |
| Package / bundle stock SKU | **No** | RD/AMC/OTG are extra **sale lines** |
| Certificate type | **No stock type** | RD Service is `attribute_id=1` catalog add-on |
| Token type | Catalog SKU only (e.g. `PPKTOKENQZ`) — still **serialized rows** in warehouse | P-01-09-06 appendix |
| Service + product combo | POS cart combination, not a stock entity | `rdserviceid` / `amcid` / `otgid` |
| Hardware vs software | `product_type` Physical / Digital / NULL — **not used to decide serials** | Digital is storefront |
| Serialized vs non-serialized | **No flag.** Every warehouse unit is a `product_stock.label`. Qty>1 only on POS add-on lines | Opening supports non-serialized for accessories **if** the team counts them without serials |

---

## G. Finance / invoice dependencies

| Need | Old storage | Needed on opening count? |
|------|-------------|--------------------------|
| POS invoice line | `selling_price` ex-GST + `gst_percentage` + `hsn_code` on **model** | SKU master selling price / GST / HSN; optional override on the row |
| GST calculation | Line GST from model; shipping 18% reverse; invoice e-invoice JSON on `orders` | GST% on SKU; **do not invent IRN/e-invoice fields** |
| Accounting | Admin invoice / TCS / TDS (`orders.tds_amount`) | Desk POS posts cash/bank + revenue; **no GST payable account yet** — cost optional for valuation |
| Cost / profit | `products.purchase_price` / `purchase_cost` / `purchase_price_actual` — **not on serial** | Optional **Unit Cost** on opening row or SKU default |
| Returns | Credit note without serial column; stock status manual | Not an opening field |
| Stock valuation | No dedicated valuation table | Unit Cost if finance wants opening value |
| Payment recon | `orders_payment`, wallet | **Not inventory** |

Desk already stores sale `unit_price`, `gst_percentage`, line tax, header discount. Opening does **not** need customer, payment, shipping, TCS, or IRN columns.

---

## Classification totals

| Class | Count | Meaning |
|-------|------:|---------|
| **KEEP** | 16 | Same fact is required on Day-1 (opening row, SKU master, or branches) |
| **REPLACE** | 13 | Old column is overloaded or unsafe; a cleaner template/Desk field replaces it |
| **DROP** | 139 | Do not carry into opening template or Day-1 Desk catalog dump |
| **UNKNOWN / NEEDS DECISION** | 9 | Owner call before import into Desk |
| **Total old fields listed** | **177** | Nothing inventory-adjacent from the inspected models/forms was omitted |

New template-only columns (no Admin column) are listed after the matrix and are **not** in the 162.

---

## Field matrix

| Old System Field | Old Table/Column | Used Where | Required? | Desk Equivalent | Keep in Opening Template? | Reason |
|------------------|------------------|------------|-----------|-----------------|---------------------------|--------|
| Product id | `products.id` | FK from `product_stock.product_id`; POS `modelid` | Required in Admin | New `inventory_products.id` | DROP — No | Desk assigns its own id. Opening keys by **SKU**. |
| Website | `products.website` | Storefront split | Optional | None | DROP — No | Not warehouse. |
| Parent product id | `products.product_id` | Links model to `is_main` listing | Optional | Optional parent/variant — **not chosen** | DROP — No | Stocked unit is the model SKU. Parent grouping is catalog, not opening count. |
| RD name | `products.rd_name` | RD listing copy | Optional | None | DROP — No | Storefront. |
| Category | `products.category_id` | Product form | Optional | None yet | DROP — No | Not required for POS serial sale. |
| Subcategory | `products.subcategory_id` | Product form | Optional | None yet | DROP — No | Same. |
| Product type | `products.product_type` | Physical/Digital/NULL | Optional | `is_serialized` (different meaning) | REPLACE — SKU Master `Serialized` | Digital vs physical is not how stock is stored. Serialized Y/N is the operational split. |
| Product name | `products.product_name` | POS, invoices, stock screen | Required on catalog | `inventory_products.name` | KEEP — Yes (SKU Master; lookup on opening) | Operators and invoices need a name. |
| Driver name | `products.driver_name` | Product form | Optional | None | DROP — No | Download metadata. |
| Short name | `products.short_name` | Inventory report, stock header | Optional | `name` | REPLACE — SKU Master name | Duplicate of product name for opening. |
| Warranty text | `products.warranty` | Catalog / AMC sibling | Optional | POS add-on SKU later | DROP — No | Sold as AMC line, not a stock condition. |
| RD Service flag/text | `products.rdservice` | Catalog | Optional | POS add-on later | DROP — No | Not a serial. |
| UPI no | `products.upino` | Catalog | Optional | None | DROP — No | Unrelated to warehouse. |
| Driver URL | `products.driver_url` | Catalog | Optional | None | DROP — No | |
| Product URL | `products.product_url` | Catalog | Optional | None | DROP — No | |
| Brand | `products.brand` | Product form | Optional | None yet | DROP — No | POS does not search brand for serials. Catalog later. |
| Color | `products.color` | Product form | Optional | Variant name later | DROP — No | Not used in stock-in/POS serial. |
| Min qty | `products.minimum_qty` | Ecom | Optional | None | DROP — No | |
| Max qty | `products.maximum_qty` | Ecom | Optional | None | DROP — No | |
| Weight | `products.weight` | Product form | Optional | None | DROP — No | Shipping, not stock count. |
| Height | `products.height` | Product form | Optional | None | DROP — No | |
| Length | `products.length` | Product form | Optional | None | DROP — No | |
| HSN | `products.hsn_code` | Invoice / POS line | Required for GST invoice | `inventory_products.hsn_code` | KEEP — Yes (SKU Master; lookup) | Needed for future invoices. 593 live products empty — fill on SKU master. |
| SKU code | `products.sku_code` | Labels, reports | Should be unique; 28 live duplicate groups | `inventory_products.sku` unique | KEEP — Yes (SKU Master + opening) | Day-1 identity. Team must pick **one** SKU per physical model. |
| Tags | `products.tags` | SEO | Optional | None | DROP — No | |
| Front image | `products.front_image` | Catalog | Optional | None | DROP — No | |
| Image alt | `products.imagealtname` | SEO | Optional | None | DROP — No | |
| Video provider | `products.video_provider` | Catalog | Optional | None | DROP — No | |
| Video link | `products.video_link` | Catalog | Optional | None | DROP — No | |
| PDF file | `products.pdf_file` | Catalog | Optional | None | DROP — No | |
| Short description | `products.short_description` | Catalog | Optional | None | DROP — No | |
| Long description | `products.long_description` | Catalog | Optional | None | DROP — No | |
| Meta title | `products.meta_title` | SEO | Optional | None | DROP — No | |
| Meta keyword | `products.meta_keyword` | SEO | Optional | None | DROP — No | |
| Meta description | `products.meta_description` | SEO | Optional | None | DROP — No | |
| Meta image | `products.meta_image` | SEO | Optional | None | DROP — No | |
| Purchase price | `products.purchase_price` | Product form; valuation | Optional (1,042 live empty) | **Missing on Desk** | KEEP — Yes (Unit Cost) | Best old cost field for valuation. Not on serial. |
| Packing/handling | `products.packing_handling` | Price calculator | Optional | None | DROP — No | Internal margin math. |
| PG charge | `products.pg_charge` | Price calculator | Optional | None | DROP — No | |
| Purchase cost | `products.purchase_cost` | Price calculator | Optional | **Missing on Desk** | REPLACE — Unit Cost | Collapses with purchase_price; do not keep two cost columns on opening. |
| Margin | `products.margin` | Price calculator | Optional | None | DROP — No | |
| Selling price | `products.selling_price` | POS cart (ex-GST, INFERRED) | Required to sell | `inventory_products.unit_price` | KEEP — Yes (SKU Master + optional opening override) | POS cannot price without it. |
| Publish price | `products.publish_price` | Tax-inclusive display | Optional | None | DROP — No | Derived from selling + GST. |
| Regular price | `products.regular_price` | Ecom | Optional | None | DROP — No | |
| Express price | `products.express_price` | Ecom | Optional | None | DROP — No | |
| GST % | `products.gst_percentage` | POS tax, invoice | Required for tax | `inventory_products.gst_percentage` | KEEP — Yes (SKU Master; lookup) | Almost always 18 on stocked models. |
| Popular | `products.popular` | Catalog | Optional | None | DROP — No | |
| Is default | `products.is_default` | Catalog | Optional | None | DROP — No | |
| Attribute id | `products.attribute_id` | 5=Model (stocked), 1=RD, 2=Warranty, 4=OTG | Required to know what is stock | `is_serialized` + product vs add-on SKU | REPLACE — Serialized Y/N | Do not import RD/AMC/OTG as warehouse serials. |
| Is main | `products.is_main` | POS parent list | Required in Admin POS | Optional grouping | DROP — No | Opening lists the **stocked SKU**, not the listing parent. |
| Technical spec | `products.technical_specification` | Catalog | Optional | None | DROP — No | |
| Hardware spec | `products.hardware_specification` | Catalog | Optional | None | DROP — No | |
| Is RD Service | `products.is_rdservice` | Catalog | Optional | None | DROP — No | |
| Max price | `products.max_price` | Catalog | Optional | None | DROP — No | |
| Select model | `products.select_model` | Catalog UI | Optional | None | DROP — No | |
| Made in India | `products.is_madeinindia` | Catalog | Optional | None | DROP — No | |
| Driver spec | `products.driver_specification` | Catalog | Optional | None | DROP — No | |
| Driver long desc | `products.driver_long_description` | Catalog | Optional | None | DROP — No | |
| Driver short desc | `products.driver_short_description` | Catalog | Optional | None | DROP — No | |
| Stock available flag | `products.is_stock_avialable` | Catalog | Derived | `inventory_stock_balances.available_qty` | DROP — No | Derived from serial/qty count. |
| Product active | `products.status` | POS only lists status=1 | Required | `inventory_products.is_active` | KEEP — Yes (SKU Master Active) | Inactive SKUs should not be counted as sellable catalog. |
| Live price | `products.liveprice` | Storefront computed bundle | Derived | None | DROP — No | Not Desk POS tax. |
| Purchase price actual | `products.purchase_price_actual` | Admin price JS | Optional | **Missing on Desk** | REPLACE — Unit Cost | Third cost field; do not keep separately. |
| Product soft delete | `products.deleted_at` | Eloquent | — | None | DROP — No | |
| Product timestamps | `products.created_at` / `updated_at` | Audit | — | Desk timestamps | DROP — No | Opening Date is the count date, not catalog created_at. |
| Stock row id | `product_stock.id` | Log `serial_id` | Required in Admin | `inventory_serials.id` | DROP — No | |
| Stock product | `product_stock.product_id` | Warehouse identity | Required | `inventory_serials.product_id` | REPLACE — SKU | Opening uses SKU code, not Admin numeric id. |
| Stock branch | `product_stock.branch` | Location slug | Required | `inventory_serials.branch_id` | REPLACE — Branch Code | New codes; do not copy `delhi_wharehouse` spelling as the Day-1 code. |
| Serial number | `product_stock.label` | Stock-in, POS assign, transfer | Required if serialized | `inventory_serials.serial_number` **global unique** | KEEP — Yes | One physical unit per row. Admin unique is weaker. |
| Credit | `product_stock.credit` | 1 unsold / 0 sold | Required in Admin | Derived from status | DROP — No | Duplicate of `is_sold`. |
| Is sold | `product_stock.is_sold` | Availability | Required | `inventory_serials.status` available/sold | REPLACE — Stock Status | Opening only Available or Damaged. Sold units are not opening stock. |
| Stock description | `product_stock.description` | Status modal | Optional | Adjustment/serial notes | KEEP — Yes (Remarks) | Count notes, damage, exceptions. |
| Stock status (overloaded) | `product_stock.status` | New/Sold/Return/Replaced/Renew/Old/Damage | Required on stock-in form | Split: condition **missing** + `InventorySerialStatus` | REPLACE — Condition + Stock Status | See mapping below. Do not copy Sold/Return as conditions. |
| Stock order id | `product_stock.order_id` | Rarely filled (120 live) | Optional | `inventory_sale_serials` after sale | DROP — No | Sale-time. Opening has no customer/order. |
| Purchase / PO id | `product_stock.purchase_id` | Excel stock-in | Required on Excel import only | `inventory_serials.batch_code` | REPLACE — optional Batch/PO on Remarks or SKU later | Optional on opening; not mandatory because physical count is the source of truth. |
| Stock soft delete | `product_stock.deleted_at` | 2,661 rows | — | None | DROP — No | |
| Stock timestamps | `product_stock.created_at` / `updated_at` | Stock screen | — | `inventory_movements.occurred_at` | DROP — No | |
| Log serial id | `product_stock_log.serial_id` | History UI | — | `inventory_movements.serial_id` | DROP — No | History not migrated. |
| Log serial no | `product_stock_log.serial_no` | History | — | Serial number on movements | DROP — No | |
| Log status | `product_stock_log.status` | Sold/Move/Rollback/Return/… | — | `inventory_movements.type` | DROP — No | |
| Log description | `product_stock_log.description` | Manual status | — | Movement notes | DROP — No | |
| Log order id | `product_stock_log.orderid` | POS assign | — | `inventory_movements.sale_id` | DROP — No | |
| Log suborder id | `product_stock_log.suborderid` | POS assign | — | Sale line | DROP — No | |
| Log created at | `product_stock_log.created_at` | History | — | `occurred_at` | DROP — No | |
| Report id | `product_stock_report.id` | Inventory screen | — | None | DROP — No | Stale snapshot. |
| Report product | `product_stock_report.product_id` | Inventory screen | — | Balances rebuilt | DROP — No | |
| Report name | `product_stock_report.name` | Cache | — | Product name | DROP — No | |
| Report short name | `product_stock_report.short_name` | Cache | — | Product name | DROP — No | |
| Report branch | `product_stock_report.branch` | Cache | — | Branch | DROP — No | |
| Report total credit | `product_stock_report.total_credit` | Cache | — | Derived | DROP — No | |
| Report total debit | `product_stock_report.total_debit` | Cache | — | Derived | DROP — No | |
| Report balance | `product_stock_report.balance` | Cache | — | `available_qty` | DROP — No | Lagged 1,823 units vs live. |
| Report refurbished | `product_stock_report.refurbuised` | Cache (typo in column) | — | Condition counts | DROP — No | |
| Report new | `product_stock_report.new` | Cache | — | Condition counts | DROP — No | |
| Report damage | `product_stock_report.damage` | Cache | — | Damaged status | DROP — No | |
| Report last updated | `product_stock_report.last_updated` | Cache | — | None | DROP — No | Max 2026-08-29 in P-01-09-06. |
| Branch id | `radium_branch.id` | Internal | — | `inventory_branches.id` | DROP — No | |
| Branch slug | `radium_branch.slug` | Stock + POS + invoice | Required in Admin | `inventory_branches.code` | REPLACE — Branch Code | New clean codes. Suggested mapping in Branches sheet notes. |
| Branch name | `radium_branch.branch_name` | POS dropdown, invoice | Required | `inventory_branches.name` | KEEP — Yes (Branches) | |
| Branch GSTIN | `radium_branch.gst_no` | Invoice / e-invoice | Required for GST invoice | `inventory_branches.gstin` | KEEP — Yes (Branches) | Public GSTIN. Bihar copied Delhi — confirm. Warehouse GST was empty. |
| Branch address | `radium_branch.address` | Invoice print | Required for invoice | None yet (name/code/gstin only) | KEEP — Yes (Branches) | Useful for future invoices; not on Desk branch schema today. |
| Branch city | `radium_branch.city` | E-invoice Addr2 | Invoice | None yet | KEEP — Yes (Branches) | |
| Branch state | `radium_branch.state` | CGST vs IGST | Invoice | None yet | KEEP — Yes (Branches) | |
| Branch pincode | `radium_branch.pincode` | E-invoice Pin | Invoice | None yet | KEEP — Yes (Branches) | |
| Branch active | `radium_branch.status` | POS list | Required | `inventory_branches.is_active` | KEEP — Yes (Branches) | |
| Invoice slug | `radium_branch.invoice_slug` | Live GST numbering | Live Admin | Desk `invoice_sequence` | DROP — No | Copying would desync Admin invoicing. |
| Invoice number | `radium_branch.invoice_no` | Live GST numbering | Live Admin | Desk sequence | DROP — No | |
| RD slug | `radium_branch.rd_slug` | RD invoice numbers | Live Admin | None | DROP — No | |
| RD number | `radium_branch.rd_no` | RD invoice numbers | Live Admin | None | DROP — No | |
| MyCafe password | `radium_branch.mycafe_password` | External integration | Secret | None | DROP — No | **Never copy.** |
| Shiprocket pickup | `radium_branch.shiprocket_pickup_id` | Ecom ship | Optional | None | DROP — No | Not POS warehouse opening. |
| Category id | `categories.id` | Product form | Optional | None | DROP — No | |
| Category name | `categories` name | Product form | Optional | None | DROP — No | |
| Subcategory id | `subcategories.id` | Product form | Optional | None | DROP — No | |
| Subcategory name | `subcategories` name | Product form | Optional | None | DROP — No | |
| Attribute id | `attributes.id` | 1 RD, 2 Warranty, 4 OTG, 5 Model | Catalog | Serialized vs add-on | REPLACE — Serialized / do not stock add-ons | |
| Attribute name | `attributes` name | Admin | Optional | None | DROP — No | |
| Brand id | `brands.id` | Product form | Optional | None | DROP — No | |
| Brand name | `brands` name | Product form | Optional | None | DROP — No | |
| Extra product category | `product_category.*` | Almost unused (2 rows) | — | None | DROP — No | |
| Product images | `product_images.*` | Catalog | Optional | None | DROP — No | |
| Unrelated model list | `model_list.*` | Not `product_stock` | — | `device_models` (support) | DROP — No | Do not auto-link. |
| Order code | `orders.ordercode` | POS | Sale-time | `inventory_sales.sale_no` | DROP — No | |
| Quotation id | `orders.quotation_id` | Ecom | Optional | None | DROP — No | |
| Order type | `orders.ordertype` | POS vs ecom vs rdservice | Sale-time | POS sales table | DROP — No | |
| RDService order id | `orders.rdservice_order_id` | Support link | Sale-time | `support_order_id` | DROP — No | Opening has no customer order. |
| Order date | `orders.orderdate` | POS | Sale-time | `completed_at` | DROP — No | Opening Date ≠ sale date. |
| Order branch | `orders.branch` | POS | Sale-time | Sale branch | DROP — No | |
| Invoice code | `orders.invoicecode` | Invoice | Sale-time | `invoice_number` | DROP — No | |
| Invoice date | `orders.invoice_date` | Invoice | Sale-time | None | DROP — No | |
| User id | `orders.userid` | Customer | Sale-time | `inventory_customers` | DROP — No | |
| User details JSON | `orders.userdetails` | Invoice | Sale-time | Customer fields | DROP — No | |
| Order state/district | `orders.state` / `district` | Place of supply | Sale-time | Customer | DROP — No | |
| Customer GST | `orders.gst_no` | Invoice | Sale-time | `inventory_customers.gstin` | DROP — No | |
| Payment type | `orders.payment_type` | POS | Sale-time | `payment_method` | DROP — No | |
| Payment id/status/details | `orders.payment_id` / `payment_status` / `payment_details` | POS | Sale-time | `payment_reference` | DROP — No | |
| Coupon | `orders.coupan_code` | Ecom/POS | Sale-time | None | DROP — No | Do not carry coupons into Desk POS Day-1. |
| No of products | `orders.no_of_products` | POS | Sale-time | Line count | DROP — No | |
| Subtotal | `orders.subtotal` | POS | Sale-time | `inventory_sales.subtotal` | DROP — No | |
| Shipping cost | `orders.shipping_cost` | POS required | Sale-time | **None (gap, do not fake)** | DROP — No | 18% reverse GST on shipping is POS-only. |
| Supply address | `orders.supply_address` | POS checkbox | Sale-time | None | DROP — No | |
| Country | `orders.country` | POS | Sale-time | None | DROP — No | |
| Discount | `orders.discount_cost` | POS header | Sale-time | `inventory_sales.discount` | DROP — No | |
| TDS | `orders.tds_amount` | Invoice | Sale-time | None | DROP — No | Do not invent TCS/TDS on opening. |
| Tax | `orders.tax` | POS | Sale-time | `inventory_sales.tax` | DROP — No | |
| Total | `orders.total` | POS | Sale-time | `inventory_sales.total` | DROP — No | |
| Received / pending / return amount | `orders.recived_amount` / `pending_amount` / `return_amount` | Wallet/credit | Sale-time | Payment fields | DROP — No | |
| Order status | `orders.status` | Workflow | Sale-time | Sale status | DROP — No | |
| Order note | `orders.note` | POS | Optional | Sale notes | DROP — No | |
| Created by | `orders.created_by` | POS operator | Sale-time | `created_by` | DROP — No | Opening uses Counted By instead. |
| E-invoice response | `orders.einvoice_respose` | GST IRN | Sale-time | Internal invoice only | DROP — No | Desk invoices are not e-invoice. |
| Order details product name | `order_details.product_name` | Invoice line | Sale-time | Line product | DROP — No | |
| Order details product JSON | `order_details.product_json` | Invoice | Sale-time | None | DROP — No | |
| Order details HSN | `order_details.hsn_code` | Invoice | Sale-time | Copied from product | DROP — No | |
| Order details product/model ids | `order_details.productid` / `modelid` | POS | Sale-time | `product_id` | DROP — No | |
| RD / AMC / OTG ids | `order_details.rdserviceid` / `amcid` / `otgid` | POS add-ons | Sale-time | Separate SKUs later | DROP — No | Not warehouse opening. |
| Order details serial JSON | `order_details.label` | After Serial() | Sale-time | `inventory_sale_serials` | DROP — No | |
| Order details qty | `order_details.qty` | POS | Sale-time | Sale line qty | DROP — No | Opening qty is physical count. |
| Order details prices | `order_details.selling_price` / `price` / `tax` / `total` | POS | Sale-time | Sale line | DROP — No | |
| Wallet ledger | `users_wallet.*` | POS wallet | Sale-time | None | DROP — No | |
| Orders payment | `orders_payment.*` | Payments | Sale-time | Sale payment fields | DROP — No | |
| Invoice table | `invoice.*` | GST invoice | Sale-time | `invoice_number` | DROP — No | |
| Credit note | `credit_note.*` | Returns | After sale | Cancel/return flow | DROP — No | No serial column. |
| Customer name/email/phone | `users.name` / `email` / `phone` | POS | Sale-time | `inventory_customers` | DROP — No | |
| Address GST | `users_address.gst_no` | POS | Sale-time | Customer gstin | DROP — No | |
| PO id | `supplier_purchase.purchase_id` | Excel stock-in gate | Required for Excel path | `batch_code` | REPLACE — optional Remarks / batch | Physical count does not require a PO. |
| PO header other columns | `supplier_purchase.*` (bill, vendor, dates) | Purchasing | Optional | None yet | DROP — No | Vendor master is not Day-1 opening. |
| PO lines | `supplier_purchase_product.*` | Purchasing | Optional | None | DROP — No | |
| Branch phone | `radium_branch` extra (if present) | Invoice? | UNKNOWN | None | UNKNOWN / NEEDS DECISION — No | Full DESCRIBE of `radium_branch` was not re-run this ticket; invoice uses address/GST/state/city/pincode. |
| Branch email | `radium_branch` extra (if present) | Invoice? | UNKNOWN | None | UNKNOWN / NEEDS DECISION — No | Same. |
| `device_models` overlap | Desk support identity vs Admin catalog | Support C360 | UNKNOWN | `inventory_products.device_model_id` | UNKNOWN / NEEDS DECISION — No | Do not auto-link on opening. |
| Which cost is “true” | `purchase_price` vs `purchase_cost` vs `purchase_price_actual` | Product form JS | UNKNOWN | Missing | UNKNOWN / NEEDS DECISION — Unit Cost one column | Template has one Unit Cost; owner picks source when filling. |
| Old → Used vs Refurbished | `product_stock.status='Old'` | Cron refurbished bucket | UNKNOWN | Condition | UNKNOWN / NEEDS DECISION — Condition still required | Template only allows New/Used/Refurbished. Team classifies what they hold. |
| Return unsold | 19 available `status=Return` | Warehouse | UNKNOWN | Condition + Available | UNKNOWN / NEEDS DECISION | Count as Refurbished (to sell) or omit if not sellable. |
| Empty branches UP/Chennai | `radium_up`, `radium_chennai` | Zero stock | Optional | Create empty branches? | UNKNOWN / NEEDS DECISION — not prefilled | Template prefills only locations that had stock. |
| Non-serialized warehouse SKUs | No flag; all stocked models have labels | Stock-in always serial | UNKNOWN | `is_serialized=false` | UNKNOWN / NEEDS DECISION — template supports both | If a box of cables is counted without serials, use blank serial + qty>1. |
| Damaged on opening | 4 Admin rows, all sold | Unsellable | Optional | `status=damaged` | UNKNOWN / NEEDS DECISION — Stock Status Damaged | Include only if physically still on premises. |

---

## Condition mapping (Admin `product_stock.status` → template)

| Admin status | Cron bucket | Opening Condition | Opening Stock Status | Notes |
|--------------|-------------|-------------------|----------------------|-------|
| New | new | **New** | Available | Only if physically unsold |
| Old | refurbished | **Used** (default) | Available | UNKNOWN if some Old are refurbished units |
| Return (unsold) | refurbished | **Refurbished** | Available | Owner may mark Used |
| Replaced / Renew | refurbished | **Refurbished** | Available | |
| Damage | damage | **Used** or **Refurbished** as the team sees it | **Damaged** | Do **not** add Damaged as a fourth condition |
| Sold | — | — | — | **Omit** from opening |

Template controlled values: **New / Used / Refurbished** only.

---

## New Day-1 fields (no Admin column)

| Field | Sheet | Required? | Why |
|-------|-------|-----------|-----|
| Opening Date | Inventory Opening | Yes | Physical count date is the source of truth |
| Location Type | Opening + Branches | Optional | Old system had no type; slug implied warehouse vs retail |
| Variant SKU | Opening + SKU Master | Optional | Desk has `inventory_product_variants`; Admin did not |
| Quantity | Inventory Opening | Yes | Admin derived from row count; non-serialized needs qty |
| Serialized Y/N | SKU Master (lookup) | Yes on SKU | Admin had no flag |
| Stock Status Available/Damaged | Inventory Opening | Yes | Replaces `is_sold` + Damage |
| Counted By | Inventory Opening | Optional | Operator accountability |
| Row Issues | Inventory Opening | Derived | Validation; never auto-correct |

---

## Compare against current Desk

Desk schema: `database/migrations/2026_09_01_120000_create_inventory_and_pos_foundation_tables.php`. **This ticket does not modify Desk.**

### Already supported

| Need | Desk |
|------|------|
| Branch code / name / GSTIN / active | `inventory_branches` |
| SKU, name, HSN, GST%, selling price, serialized flag | `inventory_products` |
| Optional variant SKU | `inventory_product_variants` |
| Serial + branch + status | `inventory_serials` (unique serial) |
| Batch / PO-ish | `inventory_serials.batch_code` |
| Available qty | `inventory_stock_balances` (derived) |
| POS price + GST + discount | `PosSaleService` |
| Transfer without clone | Relocate `branch_id` |
| Reservation | Exists in Desk; **not** an opening field |
| Damaged serial status | `InventorySerialStatus::Damaged` |

### Missing from Desk (needed or useful before opening import)

| Gap | Impact | Opening template |
|-----|--------|------------------|
| **Condition** New / Used / Refurbished | Cannot store what the team will count | Column **included**; import blocked until a Desk field or remarks convention is chosen |
| **Unit cost** | No stock valuation / profit vs `purchase_price` | Optional column |
| **Branch location type** | Warehouse vs retail | Optional column; Desk has no type |
| **Branch address / city / state / pincode** | Future GST invoice | On Branches sheet only |
| Opening business date | Stock-in uses `now()` | Opening Date column |
| Counted-by as first-class field | Actor is login user | Optional column |

### Must not carry forward

Storefront/SEO/drivers; packing/PG/margin; liveprice/publish/regular/express; clone-on-move; `credit`+`is_sold` duality; `product_stock_report`; invoice/RD sequences; `mycafe_password`; Shiprocket; wallet/coupons/shipping 18%/TCS/TDS/IRN; RD/AMC/OTG as warehouse serials; Admin numeric product ids.

### Can stay derived (do not type on every row)

Product name, GST%, HSN (from SKU Master); available qty (count of valid rows); tax amount (computed at sale); reserved qty (0 at opening).

---

## Opening template design

Workbook path (gitignored under `storage/app/private/`):

`storage/app/private/inventory-opening/rd-fresh-01-opening-inventory-template.xlsx`

Sheets: `Inventory Opening`, `SKU Master`, `Branches`, `Reference Data`, `Validation`, `Instructions`, `Field Dictionary`, `Summary`.

**No historical serials, quantities, or Admin balances.** SKU Master is blank for the team to enter Day-1 catalog. Branches prefill **suggested new codes** only (names/GSTINs that are already public on invoices), with **zero stock**.

Serialized: one unit per row, qty=1, serial mandatory. Validation **flags** (does not fix) duplicate serials, serial on two SKUs, serial on two branches. Non-serialized: blank serial, qty>1.

---

## What this ticket did not do

- No INSERT/UPDATE/DELETE on `radiumbox_prod`
- No Admin / RadiumBox / Desk production changes
- No Desk schema for Condition / Unit cost
- No import of opening rows into Desk
- Workbook not committed to Git
