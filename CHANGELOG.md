# Changelog

## 4.0.121 — 2026-09-22 — RadiumBox wallet refund response parsing (REF-67330)

- Fix `RadiumBoxWalletRefundClient` to parse verified Box wallet-refunds API response variants: numeric `wallet_reference`, `txnid` alias, and `RD{id}` derivation when `wallet_transaction_id` is present.
- Show explicit **Missing** for absent wallet ledger reference and refund detail serial number.
- Regression: `RadiumBoxWalletRefundClientTest`, `WalletRefundExecutionTest`, `Customer360WalletLedgerTest`. Prompt **RadiumDesk-P-22-09-27**. No refund execution in this release.
- Rollback target: v4.0.120 / `359fb11d`.

## 4.0.120 — 2026-09-22 — Hardware serial allocation multi-serial search (RBP415)

- Fix bulk serial search in Allocate Serials: parse comma/newline-separated serial lists with exact-match lookup instead of a single `LIKE` on the pasted string.
- Relax search validation: keep 80-character limit for single partial searches; allow up to 50 serial tokens per multi-serial query (4096 raw chars max).
- UI: paste/Enter bulk entry auto-selects matched available serials; qty 12+ orders no longer blocked around ~10 pasted serials.
- Regression: `HardwareFulfilmentSerialAllocationUiTest`, `hardware-action-dialog.test.js`. No allocation persistence changes beyond existing guards.
- Rollback target: v4.0.119 / `c79f0291`.

## 4.0.119 — 2026-09-22 — Invoice PDF horizontal rule clearance

- Move product-table row separators above each row so horizontal rules no longer intersect wrapped product text or the next row's glyphs.
- Add targeted vertical clearance in payment details, totals, serial preview, and e-Invoice verification bordered blocks.
- Presentation-only PDF changes; no statutory snapshot, tax, e-invoice payload, IRN, serial allocation, or pagination logic mutation. Regression: `StatutoryInvoicePdfPaginationTest`, `StatutoryInvoicePdfPresentationTest`, and related serial grouping suites (65 focused).
- Rollback target: v4.0.118 / `bb6697b3` (application only).

## 4.0.118 — 2026-09-22 — Invoice PDF Annexure packing and service density

- Pack grouped Annexure A serials across product/model boundaries on the same page when vertical capacity remains, instead of starting a new Annexure page for each model group.
- Flow the statutory closing block after sparse invoice content for compact single-line service invoices, while preserving bottom-anchored footer behavior for dense invoices.
- Presentation-only PDF changes; no statutory snapshot, tax, e-invoice payload, IRN, or serial allocation mutation. Regression: `StatutoryInvoicePdfPaginationTest` (88 targeted statutory PDF suites).
- Rollback target: v4.0.117 / `7f4acbd3` (application only).

## 4.0.117 — 2026-09-22 — Invoice PDF pagination: statutory footer on page 1

- Keep totals, payment details, e-Invoice verification (IRN/QR), and authorized signatory on the main invoice page instead of pushing them to a closing-only continuation page when serial preview content is present.
- Decide Annexure A from available main-page serial area capacity (complete serial set cannot fit), not from the per-model preview limit alone; preview remains capped at 10 per group when Annexure is required.
- Presentation-only PDF changes; no statutory snapshot, tax, e-invoice payload, IRN, or serial allocation mutation. Regression: `StatutoryInvoicePdfPaginationTest` plus existing serial grouping, SKU, Option B, and presentation suites (85 targeted).
- Rollback target: v4.0.116 / `a1456433` (application only).

## 4.0.116 — 2026-09-22 — Invoice PDF Annexure completeness and customer-facing SKU removal

- Fix grouped Annexure A to include every invoice-line serial group when any model requires an annexure (e.g. INV-0767211 100+5 → Annexure total 105, not 100).
- Remove catalog SKU prefixes from customer-facing PDF product and serial-group labels; stored statutory line descriptions and internal SKU data remain unchanged.
- Presentation-only PDF changes; no statutory snapshot, tax, e-invoice payload, or IRN mutation. Regression: `StatutoryInvoicePdfSerialGroupingAndServicePoTest`, `StatutoryInvoicePdfCustomerFacingSkuTest`, plus existing Option B/presentation suites.
- Rollback target: v4.0.115 / `657625cc` (application only).

## 4.0.115 — 2026-09-22 — Invoice PDF serial grouping and Service POS PO header

- Group statutory invoice PDF serial numbers by POS sale line / product model instead of flattening multi-model sales into one list.
- Preserve Option B layout: up to 10 serials per model on the main page; complete per-model lists in Annexure A when a line exceeds 10.
- Show Service POS buyer PO/reference as **PO Number** directly below **Order ID** in the invoice header; suppress duplicate **Reference No.** in Payment Details for `desk_service`.
- Presentation-only PDF changes; no statutory snapshot, tax, e-invoice payload, or IRN mutation. Regression: `StatutoryInvoicePdfSerialGroupingAndServicePoTest` plus existing Option B/presentation suites.
- Rollback target: v4.0.114 / `c7ab6f6b` (application only).

## 4.0.114 — 2026-09-22 — Service POS e-invoice parity

- Add structured service billing (city, PIN, JSON snapshot) on service quotes/orders, propagated to statutory invoice minting for B2B IRP readiness.
- Capture buyer PO/reference via existing `payment_reference` (quote → order → statutory invoice).
- Classify `desk_service` SAC 998311 for e-invoice (`UQC=OTH`, `is_servc=Y`) using existing statutory classification; reuse Product POS e-invoice pipeline.
- Add Service Sales history at `/service-pos/sales` (commerce workspace navigation).
- Add finance action to re-evaluate skipped e-invoice records without auto-submitting to IRP.
- Migration: `billing_address_structured` and `payment_reference` on `service_quotes` / `service_orders`. Regression: `ServicePosEinvoiceParityTest`.
- Rollback target: v4.0.113 / `489940ee` (application); schema rollback drops new nullable columns only.

## 4.0.113 — 2026-09-22 — Purchase Order detail tab navigation

- Fix non-functional PO detail tabs (Products, Receiving, Payments, Activity): replace disabled placeholder spans with Bootstrap 5 tab panes on the existing show page.
- Support deep links via `?tab=products|receiving|payments|activity`; PO Details remains the default tab.
- Preserve received-PO immutability: no edit routes added; released POs show an informational notice on the Details tab.
- Regression: `PurchasingPurchaseOrderDetailTabsTest`. No database migrations or PO lifecycle/pricing changes.
- Rollback target: v4.0.112 / `9169cf7a` (application only).

## 4.0.112 — 2026-09-22 — Hardware Fulfilments work queue performance

- Fix slow default Hardware Operations work queue (`/inventory/hardware-fulfilments?queue=work`): production baseline was ~15.5s server time and 12,722 DB queries because the queue loaded all ~1,003 fulfilments and ran full shipment `inspect()` per row before paginating 40 in PHP.
- Preload `channel_sku_maps` once per request (request-scoped singleton) and use lightweight operational-queue inspect for common fulfilment states, skipping Shiprocket quote/courier machinery on list rows. Detail/shipment flows still use full inspect.
- Avoid redundant per-row statutory invoice lookups when invoices are eager loaded. Regression: `HardwareFulfilmentWorkQueuePerformanceTest`.
- Rollback target: v4.0.111 / `d9aa2835` (application only).

## 4.0.111 — 2026-09-22 — POS Sales list statutory invoice display

- Fix POS Sales list Invoice column to show authoritative GST invoice numbers from `statutory_invoices.invoice_number` (via `statutoryInvoice` relation) instead of internal POS receipts (`inventory_sales.invoice_number`).
- Extend Sales list search to match statutory invoice numbers. Sales without a minted statutory invoice show `—`.
- Regression: `PosSalesListInvoiceDisplayTest`. No database migrations or invoice numbering changes.
- Rollback target: v4.0.110 / `b90ab4ec` (application only).

## 4.0.110 — 2026-09-22 — Five-destination primary navigation

- Replace the seven-group primary sidebar with five destination-only entries: Home / Desk, Commerce, Inventory, Finance, and Control & Admin.
- Consolidate detailed functions into existing workspace navigation (Commerce, Inventory, Finance, Control & Admin) without adding a third navigation tier; preserve all routes, permissions, and deep links.
- Remove Service Desk, Service Cases, and To-Dos from primary sidebar icons; move Hardware under Commerce; move Attendance, Leave, Cash Book, and Refunds into their respective workspace tabs. CA Monthly Report implementation untouched.
- Rollback target: v4.0.109 / `f458e6f0` (application only).

## 4.0.109 — 2026-09-22 — Sidebar scroll accessibility fix

- Fix primary sidebar exceeding the viewport after the v4.0.108 navigation expansion: constrain `.app-sidebar` to the viewport and make the inner `nav` region scrollable (`min-height: 0`, `overflow-y: auto`).
- Scroll the active nav link into view on load. Subtle scrollbar styling on the nav scroller for discoverability. CSS/JS only; no navigation IA, route, or permission changes.
- Rollback target: v4.0.108 / `c485c282` (application only).

## 4.0.108 — 2026-09-22 — Compact seven-group primary navigation

- Replace nine top-level sidebar groups with the approved business-oriented IA: Home, Customers & Service, Sales & Purchasing, Inventory, Finance, Workforce, and Control & Admin.
- Surface previously orphaned workflows (Purchasing, Service POS, Orders, Incidents, Refunds) via existing routes and permission gates; move Cash Book under Finance; retire Personal as a top-level group (items under Workforce).
- Presentation/navigation only: no route, schema, permission, or CA Monthly Report implementation changes.
- Rollback target: v4.0.107 / `feb5572b` (application only).

## 4.0.107 — 2026-09-22 — CA Monthly Report presentation refresh

- Refine Finance CA Monthly Report UI: prominent reporting period header, compact grouped preflight summary with expandable full metrics, unified Export Report workflow, improved recent exports and invoice preview layout. Presentation only; no export logic, permissions, or schema changes.
- Rollback target: v4.0.106 / `f8fce220` (application only).

## 4.0.106 — 2026-09-22 — CA Monthly Report page memory hotfix

- Fix production HTTP 500 on Finance CA Monthly Report index for large date ranges: preflight now processes invoices in bounded chunks instead of loading every line into memory at once.
- Default missing `date_from` / `date_to` to the current calendar month through today so the index page never queries an unbounded dataset.
- Rollback target: v4.0.105 / `1ba78ddf` (application only).

## 4.0.105 — 2026-09-22 — CA Monthly Report export hardening

- Stream chunked CSV/XLSX generation for CA Monthly Report exports; synchronous downloads up to `CA_MONTHLY_REPORT_SYNC_MAX_LINES` (default 500), larger ranges queue asynchronously.
- Async exports: `ca_monthly_report_exports` table, maintenance-queue generation job, notifications-queue email delivery, private artifact storage, signed download links for large attachments, 72h retention prune.
- Finance UI: export status polling, recent exports table, email delivery. No POS/shipping/PDF/statutory minting changes.
- Migration: `2026_09_21_150000_create_ca_monthly_report_exports_table` (new table only).
- Rollback target: v4.0.104 / `159031b8` (application); schema rollback drops `ca_monthly_report_exports` only.

## 4.0.104 — 2026-09-21 — CA Monthly Report

- Add read-only Finance **CA Monthly Report** with invoice-date filtering (`issued_at`), invoice-grain preview (expand/collapse multi-line invoices), and line-grain CSV/XLSX export.
- **27-column** Owner contract (Branch through Document Type); Ordertype Hardware/Service/Bundled; cancelled invoices included; IRN/acknowledgement from e-invoice records; invoice-level `shipping_amount` on first line only (consumes v4.0.103 schema; no POS/shipping code changes).
- Permissions: view via Finance invoices access; export via `finance.reports.export`. No database migrations.
- Rollback target: v4.0.103 / `b407e8cd` (application only).

## 4.0.103 — 2026-09-21 — POS retail Hardware shipping

- Capture pre-tax customer shipping on POS retail Hardware sales (`inventory_sales.shipping_amount`) and propagate through `issueFromPosSale` to `statutory_invoices.shipping_amount` and invoice totals/GST split.
- Counter UI: Shipping (pre-tax) field and totals row; UPI intent/verification quotes include shipping. Commerce, Service, RDService, fulfilment, and Shiprocket paths unchanged.
- Migrations: `2026_09_21_130000_add_shipping_amount_to_inventory_sales`, `2026_09_21_140000_add_shipping_amount_to_statutory_invoices` (PO sequence migration `2026_09_21_120000_initialize_purchase_order_operational_sequence` unchanged).
- Rollback target: v4.0.102 / `82b3031b` (application only; schema rollback requires separate migration plan).

## 4.0.102 — 2026-09-21 — Statutory invoice PDF serial layout (Option B)

- Fix statutory invoice PDF serial rendering for high-volume hardware invoices: main page shows the first 10 serial numbers with an Annexure A notice; Annexure A lists the complete serial set.
- Fix long production serial numbers being clipped in the PDF grid by rendering index and serial text on separate lines.
- Presentation-only renderer change; no database migrations, invoice minting, or transaction workflow changes.
- Rollback target: v4.0.101 / `721b4540`.

## 4.0.101 — 2026-09-21 — POS multi-serial selection for serialized products

- Product POS counter supports multiple serials on one invoice line: scan/paste/Enter/Tab entry, selected-serial chips, and browse filter separated from entry input.
- Batch serial validation via `GET pos.serials.match` (`PosSerialMatchEvaluator`); quantity remains synced to selected serial count through existing cart merge and `PosSaleLineNormalizer`.
- Regression: `PosSerialMatchEvaluatorTest`, `PosMultiSerialSelectionTest`, browser QA runners. No database migrations. Vite assets built at deploy time.
- Rollback target: v4.0.100 / `92ec96de`.

## 4.0.100 — 2026-09-21 — Purchasing PO release wording, FY numbering, GR complete fix

- Rename draft PO action from “Send PO” to **Release PO** (Draft → Sent only; no supplier email/transmission).
- New purchase orders use FY operational numbering: FY 2026-27 → `PO-671`, `PO-672`, … via `reference_sequences` (legacy `PO-07-*` and `PO-2026-*` preserved).
- Fix goods receipt completion HTTP 500: use `InventoryMovementType::StockIn`, remove invalid `recordMovement()` argument, idempotent replay for completed receipts.
- Migration: `2026_09_21_120000_initialize_purchase_order_operational_sequence.php`.
- Rollback target: v4.0.99 / `7bba1155`.

## 4.0.99 — 2026-09-21 — Purchase Order line keyboard entry UX

- Stop re-rendering PO product rows on every keystroke so Tab focus is preserved across Qty, Unit cost, Tax %, and Discount fields.
- Autofocus Qty when a product line is added; select-on-focus for quick value replacement; Enter advances fields without submitting the form.
- No database migrations. Vite assets built at deploy time.
- Rollback target: v4.0.98 / `fc93ce55`.

## 4.0.98 — 2026-09-21 — Purchase Order product search UX

- Replace preloaded product rows on New Purchase Order with server-side SKU/name/HSN search and add-to-lines workflow.
- Reuse `purchasing.products.search` JSON endpoint; debounced search UI with editable Qty, Unit cost, Tax %, and Discount per line.
- Duplicate product+variant selection increments quantity instead of creating a second line.
- No database migrations. Vite assets built at deploy time.
- Rollback target: v4.0.97 / `46b2d9f6`.

## 4.0.97 — 2026-09-21 — Qty-1 quantity-stock parcel packaging

- Resolve allocated inventory products from quantity-stock commitment when serial rows are absent, so non-serialized hardware fulfilments can evaluate catalog packaging correctly.
- Allow operator measured parcel entry for single-SKU qty-1 quantity-only fulfilments when verified catalog packaging is unavailable and no complete ingest parcel exists.
- Preserve parcel safety: incomplete ingest dimensions are not invented; verified catalog packaging remains preferred when present; multi-SKU and serialized qty-1 paths unchanged.
- No database migrations. Vite assets built at deploy time.
- Rollback target: v4.0.96 / `22fc39d4`.

## 4.0.96 — 2026-09-21 — RDP legacy payload-hash replay compatibility

- Accept the pre-v4.0.95 **service** canonical payload hash on channel-ingest replay for qualifying rdservice.in **RDP** `hardware_direct_buy` orders whose stored hash predates RDP hardware classification.
- `matchesStored()` still prefers the current hardware canonical hash; legacy fallback is limited to `^RDP\d+$` and exact service-hash equality.
- RIN, Box, service, and tampered payloads remain unchanged. No database migrations. Vite assets built at deploy time.
- Rollback target: v4.0.95 / `31cef0d2`.

## 4.0.95 — 2026-09-21 — RDP hardware fulfilment eligibility

- Allow paid rdservice.in `hardware_direct_buy` **RDP** orders to open Hardware Fulfilment on channel ingest, using the same RdService.in guardrails as **RIN** (`hardware_direct_buy` metadata, physical merchandise, fail-closed ingest contract).
- Preserve existing **RDE**, **RBP**, and **RIN** hardware paths unchanged.
- No database migrations. Vite assets built at deploy time.
- Rollback target: v4.0.94 / `18aea967`.

## 4.0.94 — 2026-09-20 — Refund Hold lifecycle fix

- Fix stale Refund Hold when refund completion runs on an already-closed service case: completion now clears the refund-specific active hold before marking the refund closed.
- Fix revoke/restoration path to reconcile stale refund holds via `clearRefundHoldForRefund()` after successful desk revoke.
- Wallet credit/reversal behavior unchanged; no wallet client, ledger, or API changes.
- No database migrations. Vite assets built at deploy time.
- Rollback target: v4.0.93 / `7eb52a5b`.

## 4.0.93 — 2026-09-20 — Ship & Generate Label orchestration

- Add hardware fulfilment **Ship & Generate Label** orchestration after invoice, reusing existing courier selection, shipment create, AWB assignment, and label generation services.
- Default operator flow is **2-click**: Confirm Recommended Courier → Ship & Generate Label; legacy shipment/AWB/courier endpoints preserved.
- Optional **1-click** auto-selection when `SHIPROCKET_AUTO_SELECT_RECOMMENDED_COURIER=true` (default `false`).
- Fix orchestration eligibility so ambiguous provider create timeouts can retry via existing search reconciliation.
- No database migrations. Vite assets built at deploy time.
- Rollback target: v4.0.92 / `7e197bd6`.

## 4.0.92 — 2026-09-20 — Leave notifications, Team Activity Agent DC, serial reallocation, refund timeline

- Harden leave-request notifications: designated approver receives submission alerts; requester receives approval/rejection alerts with review notes on rejection; duplicate workflow attempts do not re-notify. Uses `WORKFORCE_LEAVE_APPROVER_EMAIL` / default `shipra@radiumbox.com`.
- Team Activity: show per-agent total inbound calls plus Agent DC count (`callType=2`, `HangupBy=agent`) with disconnect icon and accessibility labels. **Calls primary metric changes from answered to total inbound.**
- Fix released hardware fulfilment serial reallocation: `released` serial rows no longer block reuse; `pending` / `reserved` / `allocated` rows still block; historical released rows retained.
- Fix Customer 360 refund timeline classification: refund audit events no longer render as “Payment received”; legitimate payment milestones unchanged.
- Catalog-price-sync unchanged; no database migrations.
- Rollback target: v4.0.91 / `45407047`.

## 4.0.91 — 2026-09-20 — RadiumBox storefront catalog price sync

- Add queued Desk → RadiumBox storefront catalog price sync on inventory product save for mapped SKUs (`POST /api/integrations/v1/catalog-prices`).
- Add B-1 storefront eligibility controls on inventory products (`sell_on_radiumbox`, `rd_service_available`, `amc_available`) with product UI status and manual retry route.
- Feature flag `RADIUMBOX_CATALOG_PRICE_SYNC_ENABLED` defaults off; restore migration files for schema already applied on production (no new reconciliation migration).
- Preserve v4.0.90 Shiprocket wallet balance banner and existing fulfilment workflows unchanged.
- Rollback target: v4.0.90 / `9a5ffa5b`.

## 4.0.90 — 2026-09-20 — Shiprocket wallet balance on Hardware dashboard

- Show a cached, read-only Shiprocket wallet balance banner on the Hardware workspace for fulfilment operators.
- Balance is informational only in Phase 1; shipping actions and fulfilment gating remain unchanged.
- Graceful degradation when Shiprocket is unavailable — never displays a false ₹0 balance.
- Rollback target: v4.0.89 / `4b8fd365`.

## 4.0.89 — 2026-09-19 — RDE legacy grouped preview

- Dashboard and Quick Create show grouped legacy preview sections for RDE hardware orders (order, customer, delivery address, product, payment, invoice, shipment) before Create Service Request.
- Preview uses radiumbox.com checkout-address snapshot with explicit PIN mismatch note when profile PIN differs.
- Dashboard legacy card layout stacks sections vertically with Create Service Request below the preview.
- Rollback target: v4.0.88 / `c2b24169`.

## 4.0.88 — 2026-09-18 — RB* service duration and invalid-GSTIN statutory support

- Represent RadiumBox RB* express `duration_price` as a separate statutory line with ₹18 GST on the ₹100 add-on when `duration_price > 0`.
- Expose `duration`, `duration_price`, `base_taxable_value`, and corrected aggregate `taxable_value` through commerce lookup mapping for statutory snapshots.
- Sanitize invalid buyer GSTIN values to B2C statutory mint; normalize legacy billing state `Dadra and Nagar Haveli` to `Dadra and Nagar Haveli and Daman and Diu`.
- Companion to deployed RadiumBox.com `service_commerce` duration fields. No production backfill in this release.
- Rollback target: v4.0.87 / `4aadf66a`.

## 4.0.87 — 2026-09-18 — RB* service GST 1-paisa reconciliation

- Allow exactly one paisa of exclusive GST drift on RadiumBox RB* service statutory invoices when authoritative publish-minus-selling tax differs from `round(taxable × rate, 2)`.
- Gate tolerance only on `radiumbox_com` RB* service mint and eligibility checks; preserve authoritative source tax without upward recalculation.
- rdservice.in RD* service, hardware inclusive GST, and global service tolerance remain unchanged.
- Rollback target: v4.0.86 / `98cfb26a`.

## 4.0.86 — 2026-09-18 — Purchasing permission constants hotfix

- Restore seven `PERMISSION_PURCHASE_*` class constants and admin-team role assignments in `RolePermissionSeeder` so authenticated Purchasing routes no longer fatal on `PurchasingAccess`.
- Add `PurchasingPermissionAccessTest` regression coverage for constants, seeder registration, and authorization gates.
- No production database seeder run required for the fatal-error fix.
- Rollback target: v4.0.85 / `487a5088`.

## 4.0.85 — 2026-09-18 — RB* service statutory invoicing integration

- Route RB* service orders to `radiumbox_com` for statutory commerce snapshots via RadiumBox `service_commerce` lookup.
- Add `RadiumBoxServiceCommerceSnapshotService`, lookup mapper, and SAC routing guard for goods HSN on service channel.
- Statutory PDF uses black logo asset; suppress unselected zero-value optional service lines on invoice presentation.
- Rollback target: v4.0.84 / `c6d55ab6`.

## 4.0.84 — 2026-09-18 — Ready for Pickup top-level hardware dashboard tab

- Add Ready for Pickup as a top-level peer tab beside Needs Action on the hardware dashboard.
- Reuse existing `readyForPickupQuery()` and workspace filter logic; no shipment/provider workflow changes.
- Preserve Shipping lifecycle sub-navigation and legacy Ready for Pickup URLs.
- Rollback target: v4.0.83 / `0a7ce286`.

## 4.0.83 — 2026-09-18 — RDE318338 historical duplicate fulfilment cancellation

- Add owner-controlled historical duplicate fulfilment cancellation for verified Desk duplicates opened after historical Admin completion (`desk:cancel-historical-duplicate-fulfilment`).
- Cancel duplicate Desk statutory invoices through the existing `StatutoryInvoiceService::cancel()` workflow; release allocated serials back to inventory; preserve Shiprocket shipment/provider error evidence without provider cancellation.
- Exclude cancelled historical duplicates from Needs Action, Ready Queue, and active shipment/AWB workflows.
- Regression lock and tests for RDE318338 / fulfilment 932 safety contract. Rollback target: v4.0.82 / `5186f4a6`.

## 4.0.82 — 2026-09-18 — RBP222 pickup-state reconciliation

- Reconcile local pickup state when Shiprocket tracking shows pickup already advanced (e.g. Out for Pickup / status 19) without calling `/courier/generate/pickup`.
- Recover from HTTP 400 `Invalid Status for pickup generation` only after a read-only provider track confirms pickup-advanced movement; otherwise preserve the provider error.
- Block Request Pickup eligibility when persisted provider track indicates pickup already advanced, even if local `pickup_requested_at` is still null.
- Add idempotent `reconcilePickupFromProviderTrack()` for controlled one-record production recovery.
- Preserve manual courier selection (recommended courier remains display-only), existing Already in Pickup Queue reconciliation, AWB/label/invoice/serial/parcel workflows, RBP103 measured parcel, WM112 mapping, Hardware Dashboard P-302, and Service Ready Queue contracts.
- Rollback target: v4.0.81 / `9e00a510`.

## 4.0.81 — 2026-09-18 — RBP222 serialized invoice state-transition fix

- Transition hardware fulfilments to `invoice_issued` immediately after durable statutory invoice mint/link, before PDF asset generation.
- Log statutory PDF and e-invoice queue failures without leaving a linked invoice stuck in `serials_allocated` (RBP222 / fulfilment 948 class).
- Preserve idempotent invoice retry: existing correlation IDs and invoice numbers are reused; duplicate mint is not attempted on retry.
- Regression tests lock PDF failure recovery, Start Shipment **Get Courier Options** modal workflow, Needs Action exclusion, and successful PDF path unchanged.
- Preserve v4.0.80 multi-SKU measured parcel, WM112 mapping, operator cable labels, Hardware Dashboard P-302, and Service Ready Queue contracts.

## 4.0.80 — 2026-09-18 — Multi-SKU quantity-stock measured parcel + WM112 mapping

- Fix parcel snapshot product resolution for quantity-stock hardware fulfilments so multi-SKU non-serialized orders (e.g. RBP103 dual replacement cables) reach **Enter Package Dimensions** in the Hardware action modal instead of a dead-end Start Shipment blocker with Open Fulfilment navigation.
- Preserve genuine parcel validation: measured outer-carton dimensions remain required when quantity > 1 or multiple SKUs are present; catalog attach rules unchanged for serialized single-SKU qty 1.
- Add verified WM112 (`model_id` 340 / Box `PDLWM112MZ`) to `desk:seed-radiumbox-hardware-sku-maps` configuration targeting Desk SKU `RBWM112MZ` (non-serialized; not `RBDKM3322W`).
- Preserve v4.0.79 operator-label vs invoice-description isolation, Hardware Dashboard P-302, Service Ready Queue, and serialized fulfilment contracts.

## 4.0.79 — 2026-09-18 — Hardware Ready Queue replacement-cable operator label

- Append an explicit `Cable` designation to verified replacement-cable model_ids (1409, 1410, 1419–1422) on the Hardware Ready Queue and hardware fulfilment workspace via `operatorLabel()`.
- Preserve exact Mantra MFS110 Type-C vs USB variant text and existing quantity formatting (`· 1 Q`, `· 1 Q +1`).
- Keep invoice and statutory PDF line descriptions unchanged through `label()` / `invoiceDescription()` (no `Cable` suffix on invoices).
- WM112 model_id 340 remains blocked (`Product mapping required`); no channel-map, inventory, or fulfilment-state changes.
- Regression tests lock operator queue labels, invoice description isolation, Hardware Dashboard P-302, and Service Ready Queue contracts.

## 4.0.78 — 2026-09-18 — Hardware product mapping and Ready Queue variant display

- Allow non-serialized Desk inventory products through Owner-approved `channel_sku_maps` with quantity stock allocation instead of serial allocation.
- Preserve serialized hardware fulfilment gates, statutory invoice guards, and existing POS/Service POS behavior.
- Show exact hardware model/variant labels on the Hardware Ready Queue from stored `model_id` and variant metadata (including Mantra MFS110 Type-C vs USB cables).
- Add idempotent `desk:seed-radiumbox-hardware-sku-maps` for verified radiumbox.com mappings (347, 1749, 1409, 1410); model_id 340 / WM112 remains blocked pending Desk catalog.
- Regression tests lock variant display, non-serialized fulfilment, seed idempotency, Hardware Dashboard P-302, and Service Ready Queue contracts.

## 4.0.77 — 2026-09-18 — Shiprocket AWB courier reliability

- Re-quote Shiprocket serviceability with `order_id` immediately before AWB assignment instead of reusing a stale stored courier.
- Keep the operator-selected courier when it is still listed; otherwise select from the fresh quote using configured preferred IDs, matching provider mode, then Shiprocket’s recommended courier.
- Recover from a definitive HTTP 400 `Given courier not serviceable` with one alternate eligible courier attempt — never reuse the rejected id, never create a duplicate shipment, and never overwrite an existing AWB.
- Cache Shiprocket login tokens across AWB requests with one bounded login retry on DNS/connect timeout; do not retry AWB after timeout or generic provider rejection.
- Regression tests lock pre-AWB re-quote, alternate recovery, idempotency, and rejection-class distinction.

## 4.0.76 — 2026-09-18 — Service Ready Queue permanent capability fix

- Restore Service Ready Queue visibility for hybrid `hardware_team` operators via `dashboard.ready_queue.view` or the settings-driven `ReadyQueueAdmin` capability, without requiring a manual admin role assignment.
- Default eligible operators to the Ready Queue workspace instead of Hardware when they have admin-queue or Ready Queue capability access.
- Add a fail-closed deployment contract (`ready-queue-service.manifest`) so `desk deploy` stops before rsync if protected Ready Queue files or markers are missing.
- Regression tests lock permission grants, capability-based visibility, hybrid default routing, and RD service task access.

## 4.0.75 — 2026-09-17 — Hardware Dashboard P-302 mainline

- Restore Hardware Dashboard P-07-09-302 into mainline: Needs Action default filter, shipping sub-filters (Ready for Pickup, Out for Pickup), received/last-activity timeline, workspace navigation, and live hardware endpoint.
- Add a fail-closed deployment contract so `desk deploy` stops before rsync if protected Hardware Dashboard files or markers are missing.
- Shiprocket tracking columns migration is included for repository parity; production schema was already applied in batch 23.

## 4.0.74 — 2026-09-17 — Independent operational reference series

- Refunds: new operational references `REF-67315+` (historical `REF-YYYY-*` preserved).
- Service orders: new operational references `SVC-671+` (legacy `SVC-000xxx` preserved).
- Product POS: new operational references `POS-6720+` (legacy `POS-000xxx` preserved).
- Dedicated `reference_sequences` counters with transactional allocation; statutory `INV-*` numbering unchanged.
- Regression tests lock generators, coexistence parsing, and concurrency safety.

## 4.0.73 — 2026-09-17 — Product POS B2B billing address propagation

- Product POS counter accepts and persists structured billing city, state, and PIN for B2B sales.
- Customer lookup restores city/state/PIN from the latest completed sale snapshot (`billing_address_structured`).
- Place of supply continues to backfill billing state when GSTIN is present and billing state is blank.
- Regression tests lock B2B address validation, customer lookup propagation, and serialized cart merge.

## 4.0.72 — 2026-09-17 — POS serialized cart merge

- Multiple serial selections for the same product/variant accumulate on one cart line with quantity equal to unique serial count.
- Server-side `PosSaleLineNormalizer` enforces the same invariant on sale completion and UPI intent creation.
- Regression tests lock same-model merge, duplicate-serial protection, and separate lines for different products.

## 4.0.71 — 2026-09-17 — Statutory GST PDF branding asset fix

- Restore `public/brand/stamp-bgr.png` and add raster `public/brand/logo.png` so statutory PDF generation does not depend on Imagick SVG rasterization under the web PHP user.
- Point invoice branding config at `brand/logo.png` instead of `brand/logo.svg`.

## 4.0.70 — 2026-09-17 — POS customer search restore

- Restore `inventory_customers` autocomplete for Product POS and Service POS counters (name, phone, email).
- Reintroduce `PosCustomerLookupService` and dedicated lookup API endpoints removed during prior route regressions.
- Service POS sellers can search customers without hardware POS view permission.

## 4.0.69 — 2026-09-17 — Production stability hotfix

- Restore overlay-dependent controllers and services required by existing routes: HistoricalOrder, LegacyCash, Purchasing, and hardware bulk documents.
- Add Purchasing models, enums, and views so restored Purchasing routes are runnable from a clean checkout.
- Fix KVM deploy preflight: ensure deploy-user ownership before rsync and rebuild route cache after deploy.
- No Service POS financial or payment behavior changes.

## 4.0.68 — 2026-09-17 — Service POS and Finance receivables

- Service Master (`/services/*`) for synthetic dev catalog management: categories, SAC, GST, pricing, and active/inactive items.
- Separate Service POS counter (`/service-pos/*`) for internal proforma quotes, service order conversion, and statutory invoice issuance via `DeskService` channel — isolated from hardware `/pos/counter`.
- Finance Hub receivables and customer payment allocation for service invoices (unpaid / partial / paid); finance journals remain disabled.
- Includes wallet refund revoke, rdservice.in wallet parse fix, and merged main-line handoff/WhiteBooks changes required for a complete production route set.
- Synthetic `ServiceCatalogSeeder` only; no Old Admin catalog import.

## 4.0.67 — 2026-09-04 — POS UPI intent and bank verification

- UPI on the POS counter creates a persisted unpaid payment intent and a local `upi://pay` QR. The QR is an instruction only and is never treated as payment confirmation.
- An authorized verifier checks the live bank account, enters the UTR, and only then does existing `completeSale()` run once.
- Cash, Card, and Bank Transfer still complete immediately. Cashfree stays on the existing non-POS orders path.
- Receiving accounts reuse `finance_bank_accounts` plus a 1:1 UPI profile. Production bank rows and `pos.payments.verify` assignments are a separate gate.
- The UPI profile receiving-account foreign key uses the explicit MariaDB-safe name `fba_upi_profiles_account_fk`.
- Desk cancel/return of a completed UPI sale still reverses stock and the journal only. It does not refund UPI.

## 4.0.66 — 2026-09-04 — Desk Inventory and POS

- Inventory and POS are available in Desk: products, branches, serial and quantity stock, transfers, adjustments, reservations, and the sales counter.
- Opening inventory can be previewed and applied from the owner Excel workbook without inventing branches or GSTINs.
- Cash, UPI, Card, and Cashfree checkout post to Finance; only Cash uses the cash account, and Cashfree uses bank clearing.
- Cancel and return restore stock and reverse the POS journal. Receipts are internal Desk receipts, not GST tax invoices.
- This release does not import opening stock, assign operators, or turn on GST e-invoice.

## 4.0.65 — 2026-09-03 — Channel Order Hub (Phase 1)

- Desk can receive paid channel orders from rdservice.net and rdservice.in via HMAC-authenticated ingest (disabled until channel secrets are configured).
- Commerce orders persist for manual statutory tax-invoice issuance; automatic invoice minting remains off.
- Finance can issue statutory invoices and private PDFs for eligible September-2026 onward orders.
- B2B invoices queue IRN foundation work only; no live IRN provider is enabled.

## 4.0.64 — 2026-08-31 — Desk Order Lookup Independence (Admin Default)

- Desk still looks up missing order details from Admin by default, so live support behavior is unchanged.
- Desk can later prefer its own saved order data, then RDService.net, then Admin, once RDService is explicitly enabled.
- Hardware (RDE/RIN) and inquiry (INQ) orders continue to use Admin.
- RDService.net stays off until production configuration is set; Cashfree remains the source of truth for payment fields.

## 4.0.63 — 2026-08-30 — RDService Order Enrichment

- After Cashfree payment, new RD orders can be enriched from RDService.net when the integration is configured.
- Cashfree remains the source of truth for payment fields; enrichment only fills missing order identity and commercial details.
- Admin lookup remains the fallback when RDService is unconfigured, unavailable, or incomplete.
- The RDService integration stays inactive until production environment configuration is set.

## 4.0.62 — 2026-08-29 — Incoming Call Lifecycle Fix

- Incoming call cards now appear when the call starts ringing, instead of waiting until hangup.
- Answered calls are recognized as soon as the caller is connected, not only after the call ends.
- A late ringing update can no longer overwrite a call that already ended as missed, busy, or answered.
- Busy, cancelled, and could-not-connect hangups are treated as missed outcomes; answered calls stay answered.

## 4.0.61 — 2026-08-29 — Team Activity Session Selection Hotfix

- Team Activity no longer shows Auto Logged Out when an open WorkSession exists for the same calendar day alongside a closed duplicate that shares the same login_at.
- Presence session selection now prefers an open session, then the latest login_at, then the highest session id, so away-timeout duplicates cannot mask an active desk session.
- Genuine Auto Logged Out after the real open session times out, and Ready Queue digest behavior, are unchanged.

## 4.0.60 — 2026-08-28 — Ready Queue Telegram Digest

- IRA Ready Queue assignments to Admin no longer send an immediate Telegram; operational Admins receive a 30-minute Ready Queue digest during their working hours instead.
- Human Admin assignment and reassignment Telegrams are unchanged.
- The digest uses the Ready Queue count and the latest Service Reference, and is not sent when the queue is empty.

## 4.0.59 — 2026-08-22 — Backup Schedule Exit Code Fix

- Fixed backup schedule status JSON so successful runs record `exit_code: 0` instead of incorrectly serializing zero as failure.

## 4.0.58 — 2026-08-22 — Backup Failure Alerting

- Production watchdog now alerts via Telegram when scheduled backups fail, stall, overlap, or become unreadable.
- Added a backup schedule wrapper and last-run status file so backup health can be monitored without shell access.
- Backup cron cutover to the new wrapper is documented in the Backup Runbook but not enabled in this release.

## 4.0.57 — 2026-08-22 — WhatsApp Outbound Cutoff

- Added an optional WhatsApp outbound cutoff so historical customer journeys do not send after Interakt credentials are restored.
- When configured, journeys that started before the cutoff skip new WhatsApp dispatches only; email and Telegram are unchanged.
- Cutoff is disabled until explicitly set in environment configuration.

## 4.0.56 — 2026-08-21 — Order Telegram Deep Link Fix

- Order identifiers in operational Telegram messages now open the Dashboard with Customer-360 auto-loaded, instead of an unstyled fragment page.
- Case and refund Telegram deep links are unchanged; text_link entity behavior is unchanged.

## 4.0.55 — 2026-08-21 — Clickable Operational Identifiers

- Authorized case, refund, and order business identifiers in operational Telegram messages are now tappable using Telegram text_link entities.
- URLs are no longer shown as separate “Open Case/Refund/Order” lines; unauthorized recipients still see plain identifiers only.
- No parse_mode changes; incoming-call, Admin, and Super Admin notification behavior is unchanged.

## 4.0.54 — 2026-08-21 — Telegram Deep Link Clickability

- Operational Telegram links (case, refund, order) now use bare HTTPS URLs that Telegram auto-links without requiring parse_mode.
- Order identifiers are shown when no authorized canonical route is available.
- Existing incoming-call, Admin, and Super Admin notification behavior is unchanged.

## 4.0.53 — 2026-08-21 — Admin Telegram Notification Policy

- Routine Admin operational alerts (staffing, unassigned scheduled work) are suppressed from 18:30 to 09:00 IST; assignments and reassignments remain immediate 24/7.
- Admin morning and evening operations summaries replace the duplicate 08:15/18:30 digests, scheduled at 10:00 and 20:30 IST with attendance, workload, SLA, and refund totals.
- Admin/Ops refund-submitted Telegram remains immediate; Super Admin notification policy from v4.0.52 is unchanged.
- Case, refund, and order Telegram messages now include authorization-safe deep links where available.

## 4.0.52 — 2026-08-21 — Super Admin Telegram Notification Policy

- Super Admin no longer receives standalone hourly SLA-risk or open-case Telegram alerts; these remain covered in morning and evening Owner Intelligence summaries.
- Super Admin no longer receives immediate Telegram when a refund request is submitted; refund pending counts and daily submission totals are now included in Owner Intelligence summaries.
- Critical watchdog alerts and existing overnight quiet-hours behavior are unchanged.

## 4.0.51 — 2026-08-21 — Super Admin Telegram Quiet Hours

- Routine overnight operational risk alerts to Super Admin are suppressed from 21:00 to 08:00 IST, including high-priority SLA and open-case alerts.
- Critical watchdog alerts continue at any time.
- Quiet-hours timing follows the pinned scheduler timezone (Asia/Kolkata).

## 4.0.50 — 2026-08-20 — Scheduler Timezone Hardening

- Pinned Laravel scheduler evaluation to Asia/Kolkata using a dedicated schedule timezone configuration.
- Added regression coverage to ensure scheduled Telegram and other IST-based jobs do not drift to UTC execution times.

## 4.0.49 — 2026-08-20 — KVM Deploy Ownership Hardening

- KVM ownership traversal now skips excluded storage/logs and node_modules paths.
- This prevents deployment failure on Supervisor-owned logs and legacy dangling node_modules symlinks.
- Regression coverage was extended for the excluded-path ownership handling.

## 4.0.48 — 2026-08-20 — KVM Deploy Hardening

- Excluded bootstrap/cache from deployment rsync so production-generated Laravel caches are preserved until optimize rebuilds them after deploy.
- Fixed KVM deploy ownership handling to skip storage/logs and apply ownership using the configured SSH user, avoiding failures on Supervisor-owned log files.
- Added regression tests for bootstrap/cache rsync protection and Supervisor-safe ownership handling.

## 4.0.47 — 2026-08-20 — Worktree-Safe Release Snapshot

- Fixed release snapshot Git metadata detection so it works correctly from linked Git worktrees used during KVM deployment.

## 4.0.46 — 2026-08-20 — KVM Doctor Redis Quoting Fix

- Fixed the KVM doctor Redis connectivity check so remote shell quoting is handled correctly and healthy Redis no longer fails preflight.

## 4.0.45 — 2026-08-20 — KVM Doctor Redis Check

- Fixed the KVM doctor Redis connectivity check so it reliably reports Laravel Redis connectivity instead of failing when Redis is healthy.

## 4.0.44 — 2026-08-20 — Backup Status, Cloud Inventory & KVM Deployment

- Added Backup Status in Administration to review local and cloud backup health, last-run outcomes, and manifest visibility without shell access.
- Improved backup manifest read access so completed runs can be verified while encrypted backup artifacts remain protected.
- Added a read-only Cloud backup inventory table in Administration, built from a sanitized local index (no credentials or remote paths exposed).
- After deployment, operators must run the Cloud inventory script on the KVM to populate the table; until then the page shows that inventory is not yet available.
- Added manual restore guidance in Administration and the Backup Runbook; restore is not available from Desk.
- Added KVM-native deployment via desk deploy using local Git and rsync to production, without remote git pull.
- desk doctor now runs KVM-specific checks for public assets, /up health, the Supervisor queue worker, Redis, and database connectivity.
- desk deploy and rollback routing block legacy shared-hosting git operations on KVM; recovery is by redeploying a known-good release tag (automated KVM rollback is not included).

## 4.0.43 — 2026-08-19 — Cloud Backup Retention

- Added a standalone Cloud backup retention tool that reports what would be kept or removed without deleting anything by default.
- Completed Cloud backups can be pruned only with an explicit execute step after a dry-run review.
- Backup scheduling and automated production prune runs are not enabled in this release.

## 4.0.42 — 2026-08-18 — Production Backup Staging

- Added encrypted local backup staging for the database and critical application secrets.
- Added optional off-server backup upload to Hostinger Cloud via SSH/rsync when explicitly enabled.
- Backup scheduling, retention pruning, and automated restore drills are not enabled in this release.

## 4.0.41 — 2026-08-18 — Historical Unknown Customer Inspection

- Added a read-only inspection command for pre-July ignored unknown_customer emails using a fixed received_at cutoff through 2026-06-30, with explicit safety exclusions before any cleanup is considered.

## 4.0.40 — 2026-08-18 — Audit Log Retention Inspection

- Added a read-only inspection command to review audit log growth, age cohorts, event categories, and retention safety before any cleanup is considered.

## 4.0.39 — 2026-08-18 — Customer Waiting Audit Event Fix

- Fixed automation scheduler failures when clearing customer waiting on already-closed service cases caused by audit log event names exceeding database limits.

## 4.0.38 — 2026-08-18 — Historical Gmail Prune Memory Fix

- Fixed historical Gmail noise prune execute mode exhausting PHP memory by batching deletes using IDs only instead of loading full email payloads.

## 4.0.37 — 2026-08-18 — Historical Gmail Noise Prune (Dry-Run)

- Added a dry-run-first prune command for pre-July historical ignored Gmail noise, reusing the inspection safety predicate with explicit --execute required for deletion.

## 4.0.36 — 2026-08-18 — Historical Gmail Noise Inspection

- Added a read-only inspection command to identify pre-July historical ignored Gmail noise by received date, with explicit safety exclusions.

## 4.0.35 — 2026-08-18 — Database Retention Prune (Dry-Run)

- Added dry-run retention prune commands for expired cache rows and completed outbox events older than 14 days.
- Added read-only database retention inspection to review growth candidates before any cleanup.
- New webhook events no longer store duplicate raw request bodies alongside parsed payloads.
- Gmail promotional, social, spam, and trash messages are skipped earlier during email intake.

## 4.0.34 — 2026-08-14 — Dead-Letter Watchdog Alerts

- Stabilized dead-letter queue Telegram alerts so the same failed jobs no longer repeat after deploy or cache clears.
- Unified watchdog dead-letter messaging across Platform Health and legacy probe paths.

## 4.0.33 — 2026-08-13 — Dashboard Navigation

- Improved Email Intake KPI hover so the full breakdown is visible.
- Removed redundant “View all service cases” dashboard links.
- Removed Orders, Refunds, and Service Cases from the left sidebar.
- Routes, permissions, dashboard workflows, and existing functionality remain available.
- Ready Queue operation was not changed.

## 4.0.32 — 2026-08-13 — Bonvoice Intake Prevention

- Unmatched Bonvoice missed calls without customer IVR input no longer create service cases.
- Known customers with matched orders still get missed-call recovery cases without requiring IVR input.
- Valid DTMF and IVR menu selections continue to create enquiry cases for unknown callers.
- Suppressed missed-call intake is recorded in audit logs for operations visibility.

## 4.0.31 — 2026-08-13 — Ready Queue Refunded Exclusion

- Refunded cases no longer appear in the Ready Queue.
- Commercial service restoration and revoke refresh Ready Queue membership in realtime.

## 4.0.30 — 2026-08-12 — Dashboard Reliability & Payment Hardening

- Fixed dashboard snapshot cache growth that could prevent operators from logging in.
- Reduced dashboard CPU by caching queue classification and batching KPI broadcasts.
- Stopped unnecessary full dashboard reloads during healthy realtime heartbeat ticks.
- Ready Queue tab counts reconcile correctly after hybrid assignment and lifecycle events.
- Added a lightweight Ready Queue membership heartbeat to keep queue badges accurate without heavy polling.
- Ready Queue rows catch up when counts change without a matching row update.
- Re-clicking the active Ready Queue tab refreshes the case list without resetting pagination.
- Removed the duplicate Ready Queue heading when queue navigation tabs are visible.
- Total Active Cases and Refunds KPIs now refresh from lightweight count endpoints with stale-aware and manual refresh controls.
- Cashfree payments link to existing orders when legacy imports and webhooks race on mixed-case order IDs.
- Cashfree reprocess tooling correctly reports existing orders instead of false recovery candidates.
- Deferred Cashfree dashboard broadcasts are disabled by default to reduce post-payment CPU load.
- Customer 360 business timelines show cleaner, deduplicated milestones with fewer noisy duplicate events.
- Gmail inbound sync uses single-flight locking to prevent overlapping sync runs.
- Platform health dead-letter queue alerts use stable fingerprints to reduce repeated Telegram noise.
- Fixed legacy service request creation from global search and legacy order intake flows with clearer validation errors.

## 4.0.29 — 2026-08-09 — Navbar Alignment

- Anchored notifications, To-Dos, and the profile menu to the far-right of the topbar.
- Constrained search width so it no longer crowds or runs into Customer 360 when the drawer is open.

## 4.0.28 — 2026-08-09 — Dashboard To-Do Layout

- Tightened the dashboard To-Do KPI card so it matches other compact KPI cards.
- Improved topbar breathing room between search, notifications, To-Dos, and the user menu.
- Cleaned Recent Customers chip spacing without changing Customer 360 behavior.

## 4.0.27 — 2026-08-09 — Contextual To-Do Modal

- Open To-Dos from the navbar, sidebar, and dashboard without leaving the current page.
- Create, edit, complete, reopen, cancel, and assign To-Dos inside a centered modal.
- Escape and stacking stay correct when Customer 360 is also open.

## 4.0.26 — 2026-08-09 — To-Dos and Reminders

- Added personal and assigned To-Dos with priority, due dates, completion, and cancel support.
- Added optional reminders that fire into the existing notification center with deep links.
- Added a minute scheduler that safely dispatches due reminders without duplicate notifications.

## 4.0.25 — 2026-08-09 — Dashboard Queue Snapshot Performance

- Operations and dashboard queue/SLA counts now reuse precomputed metrics from the active-incident snapshot cache, avoiding repeated case classification on cache hits.
- Snapshot cache default TTL increased from 20 to 30 seconds (still capped at 30) for better alignment with dashboard refresh cadence.

## 4.0.24 — 2026-08-09 — Outbox Cashfree Claim Guard

- Prevented the global outbox processor from stealing Cashfree deferred jobs while a payment's scoped drain is in flight.
- Preserved Interakt, email, and other unrelated outbox processing, plus cron recovery for true leftovers.

## 4.0.23 — 2026-08-09 — Cashfree Missed Webhook Batch Heal

- Added a Cashfree missed-webhook batch heal command for allowlisted paid orders that did not receive webhook delivery.
- Defaults to dry-run preview; writes only when `--execute` is explicitly passed.
- Recovers through the existing Cashfree webhook processor pipeline using synthetic PAYMENT_SUCCESS logs.

## 4.0.22 — 2026-08-09 — Missing Serial Scheduler Performance

- Ran missing-serial automation in the background so schedule:run is no longer blocked during outreach runs.
- Reduced skip-heavy batch work by filtering to due request, reminder, and escalation windows in SQL instead of scanning not-yet-due candidates in PHP.
- Removed duplicate eligibility checks on the skip path while preserving existing timing rules, prioritization, and customer messaging behavior.

## 4.0.21 — 2026-08-09 — Cashfree Health & Performance

- Reduced evening health report CPU by using scalar Cashfree reconciliation instead of a full payment reconcile scan.
- Optimized Platform Cashfree health warm refresh to reuse the operations cache and avoid expensive probe work on cache miss.
- Batched Ready Queue admin audit visibility queries to reduce repeated database lookups.
- Removed live slow-count queries from dashboard snapshot refresh.
- Added scheduler timing telemetry to improve CPU spike attribution in production logs.

## 4.0.20 — 2026-08-09 — Cashfree Paid-Without Discovery

- Reduced Cashfree paid-without-order integrity CPU by checking only unmatched payment candidates instead of scanning the full webhook history.
- Preserved reconcile completeness, assessment rules, and handling of payments with a missing payment-id column.

## 4.0.19 — 2026-08-09 — Automation Snapshot Hydration

- Reduced Automation Snapshot full-rebuild CPU cost by loading only the incident, order, and assignee columns required for dashboard health and queue classification.
- Preserved snapshot payload semantics, quiet-reconcile skip behavior, and periodic full-rebuild safety nets.

## 4.0.18 — 2026-08-09 — CPU Optimization Batch

- Reduced Cashfree integrity CPU cost by deduplicating alert calculation, narrowing hydrate queries, and bounding missing-order recovery discovery per run.
- Staggered overnight scheduler workloads across the hour to cut clock-aligned CPU spikes without skipping required recovery or reconciliation jobs.
- Skipped full Automation Snapshot rebuilds during quiet reconciliation when nothing changed, while preserving dirty-state and periodic full-rebuild safety nets.

## 4.0.17 — 2026-08-08 — RadiumBox Recover-Sync Scan Optimization

- Reduced unnecessary RadiumBox recover-sync candidate scanning by filtering out orders already beyond automatic recovery limits.
- Preserved stale-PENDING handling and the existing recovery, retry, enrichment, and scheduler behavior.

## 4.0.16 — 2026-08-08 — Cashfree Scoped Outbox Processing

- Scoped Cashfree deferred outbox processing to the payment incident instead of draining unrelated global pending jobs.
- Preserved processing of the payment's three deferred operations, dashboard broadcast, enrichment, and the global cron safety net.

## 4.0.15 — 2026-08-08 — Scheduler Automation Pending Limit

- Limited automation-pending grace processing per scheduler light-tick so large expired backlogs cannot run unbounded in one minute.
- Preserved Ready Queue unassigned pickup behavior after grace processing.

## 4.0.14 — 2026-08-08 — IRA Cashfree Cache-Read Briefing

- Made IRA Operations Live Cashfree health highlights cache-read-only so normal briefing never rebuilds Cashfree integrity.
- Preserved on-demand Cashfree health widget rebuild behavior for full/health surfaces.

## 4.0.13 — 2026-08-08 — BonVoice Outgoing Retirement

- Retired Desk-initiated BonVoice click-to-call and outbound live-status functionality.
- Preserved incoming BonVoice IVR, call events, live-assist, missed-call recovery, history, and reprocessing.
- Scoped incoming BonVoice webhook outbox processing to its own aggregate to prevent unrelated outbox work from running during webhook requests.

## 4.0.12 — 2026-08-08 — Ready Queue Incremental Updates

- Added incremental Ready Queue count updates for proven case additions and removals without triggering unnecessary full count reconciliation.
- Added membership-state protection to prevent duplicate or stale Ready Queue count changes across queue switches and authoritative count refreshes.
- Preserved existing Ably row updates and absolute reconciliation as the safety mechanism.

## 4.0.11 — 2026-08-08 — Ready Queue Reconcile Performance

- Optimized Ready Queue KPI reconciliation to return counts and KPI data without rebuilding Ready Queue rows.
- Reduced unnecessary database queries, PHP processing, and response payload during event-driven dashboard reconciliation.
- Preserved existing Ready Queue row updates, Ably events, heartbeat behavior, and full dashboard refresh behavior.

## 4.0.10 — 2026-08-08 — Dashboard Broadcast Performance

- Removed synchronous per-recipient KPI rebuilding from Operations dashboard service-case broadcasts.
- Preserved row and SLA broadcasts while allowing clients to reconcile KPIs through the existing dashboard refresh mechanism.
- Reduced dashboard broadcast processing from 25–38 seconds to approximately 200ms in the local 12-viewer benchmark.

## 4.0.9 — 2026-08-07 — Cashfree-First Enrichment

- Implemented Cashfree-first enrichment for paid orders, using webhook order tags to complete eligible orders without RadiumBox lookup.
- Added automatic fallback to RadiumBox enrichment only when required order data is incomplete.
- Reduced unnecessary queue jobs and external API calls while preserving existing manual sync, recovery, and legacy repair workflows.

## 4.0.8 — 2026-08-07 — Operations Live Query Efficiency

- Eliminated repeated waiting-state and order database queries during Operations Live team performance evaluation by batching operational queries and eager-loading related data.
- Optimized team performance quality scans to remove N+1 query patterns while preserving existing dashboard behavior and business logic.
- Improved cold Operations Live performance through more efficient database access with no UI or functional changes.

## 4.0.7 — 2026-08-07 — Operations Live Performance

- Optimized Operations Live full refresh to load only the bundles required for requested dashboard sections instead of rebuilding all bundles.
- Reduced unnecessary Platform Health processing during full refresh by skipping unused payment and integration diagnostics while preserving on-demand health endpoints.
- Reduced unnecessary bundle execution and SQL workload during full refresh with no UI or business logic changes.

## 4.0.6 — 2026-08-07 — Performance Hardening & Production Reliability

### Infrastructure

- OPcache max file size corrected so large PHP files are eligible for caching again
- LiteSpeed and PHP worker configuration audited against production CPU load

### Performance

- Driver Guide batch sends now process in configurable chunks to reduce long queue monopolization
- Scheduler cadence consolidated to cut unnecessary background wake-ups
- Automation snapshots refresh incrementally with event-driven invalidation
- Assign Reference batch work coalesces side effects and Driver Guide dispatch

### Reliability

- Platform Health heartbeat file no longer tracked in Git, preventing deploy pull failures
- Dashboard KPI zero-display regression investigated and root-caused
- Production CPU spikes attributed across HTTP workers, queues, and request paths
- Redis migration readiness assessed against current cache and queue usage
- Operations Live architecture mapped for safe follow-on optimization

## 4.0.5 — 2026-08-06 — Platform Email Operations

- Platform adds an Email Operations section for inbound email health, pipeline, exceptions, and recent activity
- Email Operations metrics open existing Learning Center, case, and Gmail failure screens — no duplicate tools
- IRA Learning Center row expand always shows subject and preview, with retry for extra details
- Working a Spam email (assign, create case, or link) returns it to Needs Review instead of staying in Spam
- Auto Processed renamed to Completed Automatically for operators, with clearer Handled By / Result columns
- Completed Automatically shows grouped breakdown: System Notifications, Auto Replies, Own Outbound, Bounces, Duplicate Notifications
- Review Suggested queue surfaces emails IRA is uncertain about without changing routing

## 4.0.4 — 2026-08-05 — Email Intake, Dashboard Performance & Reliability

### ✨ New

- Inbound email automatically reopens eligible closed service cases on the same order instead of creating duplicates
- Smart routing for new actionable email sends Support, Sales, and Refund enquiries to the right team with round-robin assignment
- Email Intake KPI card on the dashboard showing Needs Attention total with Sales, Orders, and Escalations breakdown on hover

### ⚡ Performance

- Faster dashboard loads with shared caching for active service case snapshots
- Team Activity panel defers roster loading until expand, improving dashboard first paint
- Faster Team Activity refresh when supervisors expand the roster
- Email Intake dashboard widget cached for smoother KPI strip updates
- Live dashboard polls patch KPIs and case rows in place when unchanged, reducing flicker and tooltip resets

### 💳 Payments

- Cashfree payment webhooks validate the configured automation user before creating paid orders
- Platform Health and Operations show Cashfree webhook secret, system user, queue, and outbox status
- Failed payment webhooks record a clear error when the automation user is missing or inactive

### 🛠 Improvements

- Customer 360 timeline shows one unified card when email reopens a closed case, with action chips and collapsed technical details
- Email Intake dashboard labels Escalations instead of Priority in the attention breakdown
- Email Intake KPI hover tooltip displays the full attention and ignored-mail breakdown again
- Customer 360 IRA Overview uses clearer agent language — RO overdue labels, Case Delay, Assigned To, and simplified status chips
- Completed refunds always close the linked service case and clear refund holds even when customer notification is unavailable

### 🐞 Fixes

- Refund completion no longer leaves service cases open when WhatsApp or email confirmation cannot be sent
- Cashfree paid orders no longer fail silently when the system automation user is misconfigured

## 4.0.3 — 2026-07-29 — Context Transparency & Commercial State

- BR-03 Context Transparency foundation
- ContextScope enum, ContextBadge, and Customer360 card catalog
- Context transparency feature flag and presenter scope metadata
- BR-02 and BR-03 documentation
- BR-04 Commercial State
- CommercialStateResolver and CommercialStateSnapshot
- Sticky Commercial State card in Customer 360
- Dashboard commercial badges and resolved-duration status label
- Commercial workflow guards for service reference, paid service, paid appointment, and charge customer
- BR-04 documentation
- Context transparency and commercial state tests

## 4.0.2 — 2026-07-28 — Deployment Tag Synchronization

- Fetch Git tags during deployment before writing the release snapshot

## 4.0.1 — 2026-07-28 — Team Activity & Customer 360 Call Intelligence

- Fix Customer 360 call status presentation for missed IVR calls
- IVR call summaries in Customer 360 communication intelligence (answered, no answer, busy, failed, and related statuses)
- BonVoice call timeline event source alignment for IVR call status handling
- Team-wide inbound IVR calls received today in the Team Activity panel
- Team Activity call metrics inbound direction filter consolidation
- Team Activity agent row and panel display updates for team-wide IVR totals
- Team Activity call metrics and Customer 360 call intelligence tests

## 4.0.0 — 2026-07-26 — P09 Workforce Platform Update

- Workforce availability intelligence
- Role management improvements
- Better assignment accuracy
- IVR foundation improvements
