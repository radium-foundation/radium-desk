# Changelog

## 4.1.19 — 2026-10-09 — Pilot REF-67392 gated re-prepare recovery

- **Central Wallet:** Owner-gated `central-wallet:pilot-refund-migration-reprepare` for refund **387** only (`reconciliation_required` → `prepared`); records new balance cutover attempt identity without mutating aborted operation **bf9f4dda-…**.
- **Schema:** `source_wallet_attempt` on `central_wallet_balance_migrations` so historical aborted attempt **0** and future attempt **1+** can coexist for the same spoke wallet row.
- **No financial execution** in this release.
- Rollback target: v4.1.18 / `fc31513f`.
- Prompt **RadiumDesk-P-04-10-173**.

## 4.1.18 — 2026-10-09 — REF-67392 false-reconcile repair + idempotency hardening

- **Central Wallet:** Treat **aborted** balance migrations as failed idempotency replay (not HTTP 200 success); require destination ledger + source retirement evidence before refund migration may reach **reconciled**.
- **Pilot repair:** `central-wallet:pilot-refund-migration-repair-false-reconcile` state-only repair for falsely reconciled pilot rows (confirm token gated).
- **Pilot import:** Persist `identity_class` as `cashfree` (fits `string(8)` schema).
- **No pilot re-execution** in this release.
- Rollback target: v4.1.17 / `596ce477`.
- Prompt **RadiumDesk-P-04-10-171**.

## 4.1.17 — 2026-10-09 — Pilot REF-67392 deploy prerequisites

- **KVM deploy:** Rsync includes only `storage/app/private/cw-pilot-refund-ref-67392-manifest.json` (pilot allowlist `[387]`) alongside `release.json`; other private storage remains excluded.
- **Ops:** Document spoke migration env (`CENTRAL_WALLET_MIGRATION_SPOKE_BASE_URL` / `CENTRAL_WALLET_MIGRATION_SPOKE_TOKEN` ↔ rdservice.in `DESK_ORDER_API_TOKEN`).
- **No financial execution:** balance/refund migration execution flags unchanged (default false).
- Prompt **RadiumDesk-P-04-10-168**.

## 4.1.16 — 2026-10-09 — Pilot single-refund Central Wallet migration engine

- **Central Wallet:** Gated pilot Type-1 refund migration path for individually owner-authorized refunds (REF-67392 fixture manifest); restores lane-1 executor, rollback, and spoke `restoreSourceCredit`; preflight/import/execute/rollback CLI; execution disabled by default.
- **Financial firewall:** No production migration execution in this release; owner approval and manifest hash gates required before any future execute.
- Rollback target: v4.1.15 / `83d3cea3`.
- Prompt **RadiumDesk-P-04-10-166**.

## 4.1.15 — 2026-10-09 — Cashfree Central Customer binding

- **Production baseline:** v4.1.14 / `f855fcf9` (Sales Report quantity and Excel line formatting preserved).
- **Central Wallet:** Full provider stack from `origin/main` @ `ed30d807` integrated into the production release line (same managed overlay inventory as production, plus CHANGE 1+2 application paths).
- **CHANGE 1 — Cashfree bind:** On Cashfree **new order create** webhook path only, bind `orders.customer_id` to exactly one Central Customer and Central Wallet using exact normalized email; reuse existing customer; idempotent; ambiguous/invalid fail-closed; **no** historical bind on `linkPaymentToExistingOrder`.
- **CHANGE 2 — Customer 360 display:** Resolve wallet from authoritative `orders.customer_id` before legacy verified `desk_email`; conflict veto unchanged; spend gates unchanged.
- **Preserved:** v4.1.13 hardware-order service-case routing; v4.1.14 Sales Report behavior; existing refund/spoke/reservation/debit gates; no schema migration.
- **Not in scope:** CHANGE 3–5, historical backfill, refund destination changes.
- **Release gate:** Non-blocking aggregate classification for documented Desk provider `customer_display` WARN (P-04-10-153).
- Rollback target: v4.1.14 / `f855fcf9`.
- Prompt **RadiumDesk-P-04-10-154**.

## 4.1.14 — 2026-10-09 — Sales Report order-line quantities

- **Total Quantity on parent row:** CA Monthly / Sales Report (`finance/reports/ca-monthly`) sums statutory invoice line `qty` for each invoice group and shows **Total Quantity** on the parent register row.
- **Product / Quantity preview:** Single-line orders show product name and quantity; multi-line orders show **Total Quantity** on the parent row with per-line quantities on expand.
- **Excel export:** Parent sheet includes **Total Quantity** column; existing financial columns and totals unchanged.
- **No schema change:** Reporting and export only. No Central Wallet, hardware routing, or wallet behavior change.
- Regression (verified on release candidate): CaMonthlyReport suite **199** passed; quantity cases **1**, **10**, and **16 + 10 = 26**; XLSX **Total Quantity** present.
- Rollback target: v4.1.13 / `9d2ff84c`.
- Prompt **RadiumDesk-P-04-10-143**.

## 4.1.13 — 2026-10-09 — Hardware order service-case routing

- **Capability-based hardware routing:** Paid hardware product orders awaiting internal serial allocation route to an active user with `hardware.fulfilment.operate`, not generic Support round-robin.
- **Defer until ingest:** When hardware fulfilment is not yet ingested, routing defers (`service_case.hardware_routing_deferred`) and retries after fulfilment becomes ready.
- **Fail closed:** When no eligible hardware operator exists, the case stays unassigned and records `service_case.hardware_routing_unresolved`.
- **Manual assignments preserved:** Existing manual or valid hardware fulfilment assignments are not overwritten by automated retry.
- **Config fail-safe:** Default `SERVICE_CASE_HARDWARE_ORDER_ASSIGNEE_EMAIL` is empty; optional email is honored only when that user can operate hardware fulfilment.
- **No historical reassignment:** This release does not backfill or reassign existing service cases.
- **No schema change:** No migration. No Central Wallet, finance, or wallet behavior change.
- Regression (verified on release candidate): hardware order service-case routing **21** passed; ServiceCaseOrderAssignmentRouting **14** passed.
- Rollback target: v4.1.12 / `aae7ddcd`.
- Prompt **RadiumDesk-P-04-10-133**.

## 4.1.12 — 2026-10-07 — Customer 360 identity credential backfill

- **Linked ensure backfill:** When a spoke calls wallet-refund-destination with attested email or mobile and an active account link already exists, Desk adds the missing verified `desk_email` or `desk_mobile` credential to that linked Desk customer only.
- **Fail-closed on conflict:** If another Desk customer already owns the same verified credential, Desk skips the backfill and does not overwrite or duplicate identity.
- **No new wallet provisioning:** Existing linked customers do not get a new Desk customer, Central Wallet, account link, ledger entry, or refund from this path.
- **Customer 360 unchanged:** Case email still requires exactly one verified `desk_email` credential. Account links, migration anchors, raw order email, and browser-supplied wallet ids are not used as identity proof.
- **No schema change:** No migration. No wallet balance change. The 50 migration-cohort credential backfill is a separate Owner gate and is not performed by this deploy.
- Regression (verified on release candidate): Customer 360 wallet tests **21** passed. Wallet refund destination identity **12** passed. Central Wallet **205** passed, **4** skipped.
- Rollback target: v4.1.11 / `997493ad`.
- Prompt **RadiumDesk-P-04-10-121**.

## 4.1.11 — 2026-10-07 — Customer 360 wallet drawer layout

- **Compact wallet rows:** The Customer 360 wallet tab uses compact transaction rows instead of a wide eight-column table. Type, amount, and business reference stay on the first line. Website, posted time, and ledger id stay on the second line.
- **Secondary details preserved:** Reservation id, source reference, correlation id, reversal linkage, status, currency, and posted time remain available under Details. Long identifiers are shortened on screen and stay copyable in full.
- **Security unchanged:** Customer 360 still requires exactly one verified Desk customer. Account links do not bypass identity. A browser-supplied wallet id is ignored. An unresolved customer does not show ₹0.
- **No wallet or schema change:** No migration. No ledger write. No refund change. No customer, credential, or account-link change. RD9064 remains wallet-unavailable until Owner identity remediation.
- Regression (verified on release candidate): Customer 360 wallet tests **19** passed. Central Wallet **205** passed, **4** skipped. Frontend build succeeded.
- Rollback target: v4.1.10 / `4875d076`.
- Prompt **RadiumDesk-P-04-10-119**.

## 4.1.10 — 2026-10-07 — RadiumBox Central Wallet refund references

- **Wallet refund reference:** When RadiumBox returns a Central Wallet credit reference, Desk keeps `CW:` followed by the positive ledger id. `CW:86` stays `CW:86` and is not rewritten to `RD86`.
- **Existing references preserved:** Numeric references and legacy `RD{id}` references remain valid. A response that omits the reference can still derive `RD{id}` from the local transaction id.
- **Invalid references rejected:** A zero id, leading zeros, lowercase `cw:`, and other malformed references are rejected instead of being rewritten.
- **No wallet or schema change:** No migration. No new credit. No `users_wallet` write. Refund completion stays on Desk. The RadiumBox Central Wallet destination flag is not enabled.
- Regression (verified on release candidate): RadiumBox wallet client, wallet refund execution, refund reference guard, and Central Wallet destination tests **60** passed.
- Rollback target: v4.1.9 / `87471510`.
- Prompt **RadiumDesk-P-04-10-117**.

## 4.1.9 — 2026-10-07 — Customer 360 Central Wallet

- **One customer wallet:** The Customer 360 wallet tab shows one verified Desk customer's Central Wallet: available balance, active reservations, and posted history from radiumbox.com, rdservice.in, and rdservice.net.
- **Identity stays on the server:** The case email only finds that one verified customer. The wallet id comes from the Desk customer record. A browser-supplied wallet id is ignored. A conflicting customer id, or a customer with no wallet id, leaves the wallet unresolved and does not show ₹0.
- **Agent access:** An agent with wallet view permission can open the tab. That permission does not open the Finance module.
- **No wallet or schema change:** No migration. No ledger write. No refund change. No `users_wallet` write.
- Regression (verified on release candidate): dedicated 360 wallet tests **17** passed. Central Wallet **166** passed, **4** skipped. Wallet/refund **78** passed and **1** pre-existing parent failure. Customer 360 **25** passed and **1** pre-existing parent failure.
- Rollback target: v4.1.8 / `ca6d59fd`.
- Prompt **RadiumDesk-P-04-10-113**.

## 4.1.8 — 2026-10-07 — Central Wallet customer transaction history

- **Customer wallet history:** RadiumBox can again show a customer's Central Wallet transaction history, including posted entries from other Radium sites on the same wallet.
- **Balance unchanged:** This release restores the history read only. It does not change wallet balances, ledger entries, reservations, or refunds.
- **Site reads preserved:** A site's own ledger read remains limited to that site. A valid wallet with no posted entries returns an empty history.
- **No schema change:** No migration. No `users_wallet` write. No refund completion.
- Regression (verified on release candidate): customer-history API **15** passed; Central Wallet plus wallet/refund regression **231** passed, **4** skipped.
- Rollback target: v4.1.7 / `7ba534c3`.
- Prompt **RadiumDesk-P-04-10-105**.

## 4.1.7 — 2026-10-07 — rdservice.in wallet refund references

- **Wallet refund completion:** rdservice.in wallet refunds can complete when the Central Wallet reference is `CW:` followed by a positive ledger id. Existing credits that already returned this reference can finish through the normal refund completion flow.
- **Existing references preserved:** Numeric wallet references and `RD` order references continue to be accepted.
- **Invalid references still rejected:** Malformed Central Wallet references, including a zero id, leading zeros, lowercase `cw:`, spaces, and other punctuation, remain rejected.
- **Checks unchanged:** Amount, currency, source, and refund-reference validation are unchanged. Replaying the same refund does not create a second Central Wallet credit.
- **No wallet or schema change:** No migration. No new credit, no `users_wallet` write, and no change to rdservice.in or payment flows.
- Regression (verified on release candidate): wallet/refund suite **65** passed, including the rdservice.in wallet refund client (**15**) and wallet refund execution (**20**).
- Rollback target: v4.1.6 / `584dd066`.
- Prompt **RadiumDesk-P-04-10-102**.

## 4.1.6 — 2026-10-07 — CA Sales Report e-invoice / IRN evidence columns

- **E-invoice evidence columns:** Sales Report parent sheet adds **E-Invoice / IRN Generation Status**, **E-Invoice / IRN Response Code**, and **E-Invoice / IRN Response Reason** after Acknowledgement. Values are read from persisted `e_invoice_records` via `StatutoryInvoice.eInvoiceRecord` — provider codes/messages are exported verbatim from stored `response_payload`; skip reasons and IRP field gaps are exported for non-submitted cases; invoices without an e-invoice record show an explicit not-available status/reason rather than implying failure.
- **GSTIN Format Status preserved:** Local GSTIN format validation remains a separate column and is unchanged.
- **Accounting behavior preserved:** Invoice grain, GST totals, Credit Note handling, payment channel logic, permissions/routes, and wallet/refund accounting unchanged. No migration. No IRN regeneration, wallet, refund-journal, statutory-adjustment, invoice cancellation, or Credit Note changes.
- Regression (verified on release candidate): CaMonthly unit + feature suite; AST300 export regression; Pint PASS.
- Rollback target: v4.1.5 / `08d30030`.
- Prompt **RadiumDesk-P-04-10-97**.

## 4.1.5 — 2026-10-07 — CA Sales Report State, Date_of_order, and GSTIN format

- **Shared State resolver:** `CaMonthlyReportStateResolver` centralizes buyer State for the Sales Report register. Precedence: invoice billing snapshot structured state → linked commerce order structured billing state → commerce `billing_state` → invoice `place_of_supply_state` → linked POS inventory sale structured billing state → linked service order structured billing state → service `billing_state` / `place_of_supply_state`. **GSTIN is not used as a State source.**
- **Preflight alignment:** Preflight State warnings now evaluate the exported register State column (same resolver as XLSX/CSV), not invoice snapshot alone.
- **Service Date_of_order fix:** Service invoices with non-numeric source IDs (e.g. `SVC-*`) resolve order date and order ID through `service_orders.order_number` instead of incorrectly casting `source_id` to integer zero.
- **GSTIN Format Status column:** Parent sheet adds **GSTIN Format Status** after GSTIN with values **Not present**, **Format valid — registration not verified**, or **Format invalid**. Uses local GSTIN format validation only — **does not verify GST registration status** and does not call an external GST taxpayer API.
- **Accounting behavior preserved:** Invoice grain, GST totals, Credit Note handling, payment channel logic, permissions/routes, and wallet/refund accounting unchanged. No migration. No wallet, refund-journal, statutory-adjustment, invoice cancellation, or Credit Note changes.
- Regression (verified on release candidate): CaMonthly unit + feature suite **186** passed; Pint PASS.
- Rollback target: v4.1.4 / `ad5047ac`.
- Prompt **RadiumDesk-P-04-10-91**.

## 4.1.4 — 2026-10-06 — Sales Report export correction

- **Sales Report filename:** XLSX/CSV/email downloads now use `sales-report-%s-%s.%s` instead of `ca-monthly-report-*`.
- **Main sheet columns:** Customer Type removed from the Sales Report parent sheet (remains on Refund & CN Review where applicable). **Product Name** added as a parent column sourced from `statutory_invoice_items.description`.
- **Desk State preserved:** Invoice billing snapshot → linked order structured billing state → `commerce_orders.billing_state`.
- **Branch Name preserved:** Existing `CaMonthlyReportBranchResolver`; column title **Branch Name**.
- **XLSX page setup:** `fitToPage` moved under `pageSetUpPr` (Excel-compatible); no longer on invalid `pageSetup` node.
- **Refund & CN Review preserved:** Second worksheet unchanged in scope.
- **Accounting behavior preserved:** Invoice grain, GST totals, Credit Note handling, permissions/routes, wallet/refund accounting unchanged. No migration. No wallet, refund-journal, or statutory-adjustment changes.
- Regression (verified on release candidate): focused Sales Report suite **52** passed (`CaMonthlyReportSalesReportTest`, `CaMonthlyReportPaymentChannelTest`, `CaMonthlyReportCaReadyExportTest`, `CaMonthlyReportPartialPaidToleranceTest`, `CaMonthlyReportXlsxCompatibilityTest`).
- Rollback target: v4.1.3 / `fe47927f`.
- Prompt **RadiumDesk-P-04-10-84**.

## 4.1.3 — 2026-10-06 — Central Wallet wallet-refund destination recovery + deploy safety

- **Central Wallet wallet-refund destination (source restore):** Reintroduces `GET /api/central-wallet/v1/wallet-refund-destination` and its dependency chain after the endpoint was lost during the v4.1.2 recovery overlay. Resolves Desk CWID for spoke wallet refund credits via canonical customer identity ensure — **read-only destination resolution; no ledger credit or refund execution in this endpoint.** Spending authorization and wallet refund execution remain separate.
- **Customer identity support:** Adds customer-identity tables, credential hashing, historical contact-index matching, and `desk_customer_id` on account links required by the destination endpoint. Migrations are additive; MariaDB production semantics unchanged. SQLite test compatibility restores ceremony partial unique indexes after the identity migration table rebuild.
- **Feature flags (defaults unchanged for production overlay):** `CENTRAL_WALLET_HISTORICAL_WALLET_VISIBILITY_ENABLED` defaults **false** — endpoint returns 503 until explicitly enabled post-deploy with contact index configured. `CENTRAL_WALLET_CUSTOMER_IDENTITY_ENSURE_ENABLED` and `CENTRAL_WALLET_WALLET_REFUND_DESTINATION_ENABLED` default **true** in source. **Not deployed; production remains v4.1.2 until a separate authorized deploy.**
- **Wallet/refund execution preserved:** No changes to `WalletRefundExecutor`, refund status transitions, CN flows, ledger debit/reservation APIs, or direct-ledger-debit gate behavior.
- **Deployment rsync safety:** Extends KVM deploy tooling with centralized deletion safety gate, dry-run `--dry-run` enforcement, read-only dry-run preflight separation from live release-tag validation, and hardened rsync excludes for local dev artifacts (`.env.sqlite`, `database/*.sqlite`, `.git`, `.DS_Store`, `.cursor/`). Filter-leak regression test added.
- **Service POS:** Inline Ex-GST price editing on Service POS catalog lines (included in release history).
- **Source-only release note:** This changelog describes repository capability at tagged release time. **Does not imply production deployment, production endpoint activation, or execution of pending wallet refunds (e.g. REF-67372).**
- Regression (previously verified on `8d5d88f0`): Central Wallet **151** passed / **4** skipped; WalletRefund **73/73**; WalletRefundDestination **20/20**; extended wallet-refund bundle **72/72**; security **8/8**; idempotency **6/6**; deployment-safety scripts PASS; Pint PASS.
- Rollback target: v4.1.2 / `59b717e7`.
- Prompts **RadiumDesk-P-04-10-64** through **RadiumDesk-P-04-10-71**.

## 4.1.2 — 2026-10-05 — CA-ready Sales Report

- **CA-ready Sales Report XLSX:** Sheet 1 adds CA handoff columns — Customer Type, Total GST, Payment Status, Credit Note Number/Status; Payment Channel renamed to **Payment Method**; line detail adds Unit Price, Discount, and GST Rate. Parent invoice grain, expandable product detail, and CSV parent-only export unchanged.
- **Refund & CN Review sheet:** Second XLSX sheet lists exception-only refunds completed in the reporting period that require CA/Finance review (not a full refund register). Scope documented in the sheet period row.
- **Product detail preserved:** Product Name (`statutory_invoice_items.description`) and Product Code / SKU (`statutory_invoice_items.sku`) remain on expandable line rows and web preview.
- **Refund review timing:** IRN age for CA classification is measured at **refund completion time** (`executed_at` / `closed_at`), not current time. Neutral review flags only — potential statutory Credit Note treatment (B2B + submitted IRN + full refund + no linked CN beyond cancellation window); refund-before-invoice timeline anomaly.
- **Refund vs Credit Note separation:** Refund amounts are reported separately from Credit Note Number, Status, and Amount. Refunds are not labelled as GST Credit Notes.
- **Accounting behavior preserved:** Invoice-level totals, GST calculations, Credit Note display on original invoices, permissions/routes, and wallet/refund accounting unchanged. No migration. No wallet, refund-journal, or statutory-adjustment changes.
- Regression (previously verified): CaMonthly report suite **146** passed; `CaMonthlyReportCaReadyExportTest` **7** passed; `CaMonthlyReportXlsxCompatibilityTest` **9** passed; Pint PASS.
- Rollback target: v4.1.1 / `77276772`.
- Prompt **RadiumDesk-P-04-10-41**.

## 4.1.1 — 2026-10-05 — Sales Report (CA Monthly register rename + product detail)

- **User-facing rename:** CA Monthly Report is now presented as **Sales Report** (navigation, page title, export email, XLSX sheet/title, audit label). Internal route identifiers (`finance.reports.ca-monthly.*`) and report ID (`statutory.ca_monthly`) unchanged for backward compatibility.
- **Product detail:** XLSX expandable line detail and web preview now expose **Product Name** (`statutory_invoice_items.description`) and **Product Code / SKU** (`statutory_invoice_items.sku`, blank when null).
- **Accounting grain preserved:** One invoice-level parent row per statutory invoice (22 columns); invoice totals, GST, and Credit Note aggregation unchanged. Multi-product invoices remain one parent row with expandable line detail in XLSX; CSV remains parent-only.
- **Credit Note behavior preserved:** Status column and separate credit-note rows unchanged; no refund-amount columns added.
- **No migration.** No wallet, refund-journal, or statutory-adjustment changes.
- Regression: `CaMonthlyReportSalesReportTest`, CA Monthly report suite, `FourMenuNavigationTest`.
- Rollback target: v4.1.0 / `8212bbc8`.
- Prompt **RadiumDesk-P-04-10-38**.

## 4.1.0 — 2026-10-02 — rdservice.net Central Wallet refund destination (companion, flag OFF)

- **rdservice.net wallet refunds:** When `RDSERVICE_NET_WALLET_REFUND_CREDIT_ENABLED=true` and the `rdservice_net` order-lookup spoke is configured, Desk wallet approvals and execution for RN/RA/RNP orders POST to rdservice.net `/api/integrations/v1/wallet-refunds`. The spoke resolves trusted identity and credits the authoritative Desk Central Wallet ledger — not a local spoke wallet.
- **Central Wallet hotfix:** `WalletController::appendLedgerEntry` closure now captures `$entryType` correctly (regression from v4.0.168 direct-debit gate refactor). Restores external ledger credit/debit append after gate check.
- **Fail-closed defaults:** Both `RDSERVICE_NET_WALLET_REFUND_CREDIT_ENABLED` and `RDSERVICE_NET_LOOKUP_ENABLED` remain **false**. With flags off, rdservice.net wallet approval/execution continues to reject with the existing Cashfree/other payout guidance.
- **Preserved:** rdservice.in and RadiumBox wallet refund clients/executors, Cashfree and bank-transfer payout paths, refund approval/outbox gates, and Central Wallet ledger SSOT unchanged. Desk does not trust spoke-provided CWIDs or create identity from contact fields alone.
- **Companion:** Requires rdservice.net `CENTRAL_WALLET_REFUND_DESTINATION_ENABLED` (also default **false**) from commit `c975940` (RDServiceNet-P-02-10-01). **Not deployed to production in this release.**
- Regression: `WalletRefundExecutionTest`, `WalletRefundRdServiceNetApprovalGuardTest`, `RdServiceNetWalletRefundClientTest`, `WalletRefundDestinationResolverTest`. Prompt **RadiumDesk-P-02-10-02**.
- Rollback target: v4.0.168 / `013ec2c6`.

## 4.0.168 — 2026-09-30 — Central Wallet external direct debit safety gate (default ON)

- **Safety gate:** `CENTRAL_WALLET_DIRECT_LEDGER_DEBIT_ENABLED` (default **true**) controls whether external site callers (`X-Site-Code`) may POST `entry_type=debit` to `/wallets/{cwid}/ledger-entries`. When false, rejects with **503** before idempotency/ledger mutation.
- **Preserved:** Reservation commit debits, credits, adjustments, reversals, balance migration, internal `central_wallet_service` callers, and all existing Central Wallet auth/source-system behavior unchanged.
- **Production intent:** Flag remains **true** — no change to current direct-debit availability until checkout cutover explicitly sets false.
- Regression: `CentralWalletDirectLedgerDebitGateTest` (**14**) + Central Wallet suite PASS. Prompt **RadiumDesk-P-30-09-14**.
- Rollback target: v4.0.167 / `f826d9e3`.

## 4.0.167 — 2026-09-30 — Central Wallet reservation lifecycle foundation (additive, flag OFF)

- **Reservation foundation:** State machine, `ReservationService`, API routes/controllers, expiry job, source-system security, and reversal linkage for Central Wallet holds (reserve → commit/release/expire).
- **Migrations (additive only):** `central_wallet_reservations` table with wallet/state/expiry indexes; `original_ledger_entry_id` reversal-linkage column on `central_wallet_ledger_entries`. No balance backfill, no historical wallet migration.
- **Feature flag:** `CENTRAL_WALLET_RESERVATIONS_ENABLED` remains **false** in production — reservation endpoints gated; no operational reservation activity.
- **Preserved:** Existing ledger debit/credit, wallet refund, ceremony, M2, cross-site linking, and balance-migration infrastructure unchanged. No RadiumBox or rdservice.in changes.
- Regression: `CentralWalletReservationTest` and Central Wallet suite **127** tests (**123** pass / **4** skip). Prompt **RadiumDesk-P-30-09-11**.
- Rollback target: v4.0.166 / `6b1ee86d`.

## 4.0.166 — 2026-09-30 — Login page rectangular logo

- **Login:** Guest layout now uses canonical transparent rectangular `brand/logo.png` (196px max-width, Invoice-aligned) instead of compact `brand/icon.svg`.
- **Preserved:** Sidebar expanded/collapsed branding, outgoing email `logo.png` header, authentication flow, canonical logo assets. No migrations.
- Regression: `PlatformIdentityTest` login branding PASS; `EmailMasterLayoutTest` PASS. Prompt **RadiumDesk-P-30-09-07**.
- Rollback target: v4.0.165 / `b8fa4104`.

## 4.0.165 — 2026-09-30 — Desk branding logo fixes

- **Email:** Outgoing notification headers use email-safe `brand/logo.png` instead of `brand/icon.svg` (SVG blocked by many mail clients).
- **Login:** Transparent compact `icon.svg` on the guest login card.
- **Sidebar:** Expanded state shows rectangular `logo.png` on a white brand header; collapsed state keeps compact `icon.svg`. Removed CSS `brightness(0) invert(1)` filter that rendered an unreadable white blob.
- **Preserved:** Authentication flow, sidebar expand/collapse, email master layout, canonical logo assets. No migrations.
- Regression: branding/email/platform identity tests PASS. Prompt **RadiumDesk-P-30-09-05**.
- Rollback target: v4.0.164 / `48d14d49`.

## 4.0.164 — 2026-09-30 — Incoming email OOM + automation sync label hotfix

- **Incoming email OOM:** `IncomingEmailAttentionCategoryService::knownCustomerEmails()` now uses database-side `DISTINCT` before `pluck('customer_email')`, so repeat customer orders no longer materialize one PHP string per order row (verified 128MB production OOM on dashboard email-intake KPI path).
- **Automation snapshot:** `AutomationOperationsValidationCollector::radiumBoxSyncLabel()` delegates to `RadiumBoxEnrichmentSyncStatus::label()` for all seven sync statuses, fixing `UnhandledMatchError` on `HandoffPending`, `HandoffFailed`, and `ReconciliationRequired` during `automation:snapshot`.
- **Preserved:** Soft-delete scope on order lookup, intake categorization semantics, and existing labels for the original four sync statuses. No `memory_limit` change. `OrderIdentityValidationAnalyzerService` unchanged.
- Regression: `IncomingEmailAttentionCategoryServiceTest` (**3/3**), `AutomationOperationsValidationCollectorSyncLabelTest` (**3/3**). Prompt **RadiumDesk-P-30-09-04**.
- Rollback target: v4.0.163 / `88a9c7dc`.

## 4.0.163 — 2026-09-30 — Central Wallet cross-site linking + migration safety infrastructure

- **Cross-site ceremony:** `CrossSiteCeremonyResolver` resolves existing CWID across sites via verified phone hash when `CENTRAL_WALLET_CROSS_SITE_CEREMONY_ENABLED=true` and explicit `cross_site_link_authorization_ref` is supplied; `provision_action: resolved_cross_site_existing`.
- **Cohort gate:** `CENTRAL_WALLET_CROSS_SITE_CEREMONY_COHORT_ENABLED` + `CENTRAL_WALLET_CROSS_SITE_CEREMONY_COHORT_LOCAL_USER_IDS` fail-closed before cross-site resolution (default **OFF** / empty).
- **Migration safety:** `central_wallet_balance_migrations` table, cutover service/controller, state machine; `CENTRAL_WALLET_BALANCE_MIGRATION_EXECUTION_ENABLED` default **false** (no money movement).
- **Spoke client:** `HttpWalletMigrationSpokeClient` for rdservice.in lock/retire/status APIs; reconciliation verify before `RECONCILED`.
- **Preserved:** M2 confirm, ceremony complete, revoke, ledger, wallet refund flows unchanged. No production migration execution in this release.
- Regression: Central Wallet suite **132** tests (**128** pass / **4** skip). Prompt **RadiumDesk-P-28-09-07**.
- Rollback target: v4.0.162 / `efceb854`.

## 4.0.162 — 2026-09-30 — Central Wallet account-link revoke API

- **Revoke endpoint:** `POST /api/central-wallet/v1/account-links/{link_id}/revoke` exposes `AccountLinkService::revokeLink` through the established Central Wallet API boundary.
- **Authorization:** Bearer integration token plus caller `X-Site-Code`; `local_user_id` in request body must match the link row; cross-site and cross-user revoke rejected.
- **Idempotency:** Uses existing idempotency service; already-revoked links return **200** without duplicate audit events.
- **Preserved:** CWID, Central Wallet ledger, ceremony identity, M2 confirm, ceremony complete, balance, and wallet refund flows unchanged. No migration.
- Regression: `CentralWalletApiTest` (**24/24** PASS), Central Wallet feature suite (**76** tests, **72** passed, **4** skipped), `AccountLinkServiceTest` (**2/2** PASS). Prompt **RadiumDesk-P-28-09-02**.
- Rollback target: v4.0.161 / `cfafd2e9`.

## 4.0.161 — 2026-09-29 — Central Wallet ceremony wallet uniqueness MariaDB 11.8.8 fix

- **Migration fix:** `active_site_wallet_uniq_key` STORED generated column now wraps `central_wallet_id` with `RTRIM()` so MariaDB 11.8.8 accepts the wallet active-link uniqueness index (`CHAR(36)` in `CONCAT()` previously failed with error 1901).
- **Production resume:** Idempotent against current partial state where `active_site_user_uniq_key` and `cw_account_links_site_user_active_uq` already exist but wallet column/index are absent after the failed v4.0.160 deploy attempt.
- **Invariants preserved:** One active account link per `(site_code, local_user_id)` and per `(site_code, central_wallet_id)`; multiple inactive links permitted; different site codes remain independent.
- **Unchanged:** Ceremony complete API, M2 confirm, ledger, wallet refund, hardware shipping migration, and existing site-user generated column/index.
- Regression: `CentralWalletCeremonyMigrationTest` (**8/8**), `CentralWalletCeremonyMariaDbMigrationTest` (**5/5** on MariaDB 11.8.8), full Central Wallet regression (**85/85** PASS). Prompt **RadiumDesk-P-25-09-127**.
- Rollback target: v4.0.160 / `c0057afc`.

## 4.0.160 — 2026-09-29 — Central Wallet ceremony migration MariaDB compatibility fix

- **Migration fix:** `2026_09_28_150000_create_central_wallet_ceremony_tables` now uses idempotent table/index creation and MariaDB-compatible STORED generated columns for partial unique active-link constraints (replaces unsupported functional `IF()` unique indexes that failed on MariaDB 11.8.8).
- **Production resume:** Safe to re-run on partial state where ceremony tables already exist but migration remained Pending after v4.0.159 deploy attempt.
- **Invariants preserved:** One active account link per `(site_code, local_user_id)` and per `(site_code, central_wallet_id)`; multiple inactive links permitted.
- **Unchanged:** Ceremony complete API, M2 confirm, ledger, wallet refund, and already-applied `2026_09_28_120000_add_hardware_external_shipping` migration.
- Regression: `CentralWalletCeremonyMigrationTest` (**6/6**), `CentralWalletCeremonyCompleteTest`, Central Wallet API/ledger, and `WalletRefundExecutionTest` (**79/79** PASS). Prompt **RadiumDesk-P-25-09-124**.
- Rollback target: v4.0.156 / `c7ec5c5e` (pre-ceremony production baseline).

## 4.0.159 — 2026-09-29 — Central Wallet ceremony complete API on mainline

- **Mainline integration:** Merge `release/central-wallet-ceremony-v4.0.158` (reviewed @ `8ebabab1` / tag `v4.0.158`) into `main` for deploy-ready Central Wallet automatic linking.
- **Ceremony complete endpoint:** `POST /api/central-wallet/v1/ceremony/complete` atomically validates Box-signed ceremony proof, resolves or creates CWID by `(site_code, local_user_id)`, and creates an active account link in one transaction.
- **Proof validation:** Per-site HMAC-SHA256 ceremony verification ref with 5-minute TTL, single-use JTI consumption, and `verified_phone_e164_hash` as evidence only (not identity).
- **Identity model:** Ceremony identity keyed by site-local account (`site_code` + `local_user_id`); phone hash is verification evidence, not the primary identity key.
- **Migration:** `2026_09_28_150000_create_central_wallet_ceremony_tables` adds `central_wallet_ceremony_identities` and `central_wallet_ceremony_proof_consumptions` (additive; no changes to existing CW tables).
- **Config support:** `CENTRAL_WALLET_CEREMONY_SIGNING_SECRET_RADIUMBOX_COM` and `CENTRAL_WALLET_CEREMONY_SIGNING_SECRET_RDSERVICE_IN` (env-only; not provisioned in this release).
- **Preserved:** Existing M2 confirm API, ledger read/write APIs, wallet refund orchestration, and unrelated Desk functionality unchanged.
- Regression: `CentralWalletCeremonyCompleteTest` (**19/19** PASS) plus Central Wallet, wallet refund, and ledger regression suites. Prompt **RadiumDesk-P-25-09-122**.
- Rollback target: v4.0.156 / `c7ec5c5e`.

## 4.0.158 — 2026-09-29 — Central Wallet ceremony complete API (automatic linking)

- **Ceremony complete endpoint:** `POST /api/central-wallet/v1/ceremony/complete` atomically validates Box-signed ceremony proof, resolves or creates CWID by `(site_code, local_user_id)`, and creates an active account link in one transaction.
- **Proof validation:** Per-site HMAC-SHA256 ceremony verification ref with 5-minute TTL, single-use JTI consumption, and `verified_phone_e164_hash` as evidence only (not identity).
- **Identity model:** Ceremony identity keyed by site-local account (`site_code` + `local_user_id`); phone hash is verification evidence, not the primary identity key.
- **Migration:** `2026_09_28_150000_create_central_wallet_ceremony_tables` adds `central_wallet_ceremony_identities` and `central_wallet_ceremony_proof_consumptions`.
- **Config support:** `CENTRAL_WALLET_CEREMONY_SIGNING_SECRET_RADIUMBOX_COM` and `CENTRAL_WALLET_CEREMONY_SIGNING_SECRET_RDSERVICE_IN` (env-only; not provisioned in this release).
- **Preserved:** Existing M2 confirm API, ledger read/write APIs, wallet refund orchestration, and unrelated Desk functionality unchanged.
- Regression: `CentralWalletCeremonyCompleteTest` (**19/19** PASS) plus Central Wallet, wallet refund, and ledger regression suites. Prompt **RadiumDesk-P-25-09-116**.
- Rollback target: v4.0.156 / `c7ec5c5e`.

## 4.0.156 — 2026-09-28 — Shiprocket courier reassignment reconciliation (RBP611)

- **Reconcile Courier Reassignment:** When Shiprocket Admin reassigns a bound AWB shipment to a new courier/AWB, Desk can realign local shipment state from provider search without calling assign, pickup, label, or manifest APIs.
- **Audit trail:** Prior AWB, courier, and label URL are preserved in `shipment_events` (`source=reconcile`, `activity=awb_reassigned`); stale labels are cleared; `pickup_requested_at` is retained.
- **Safety:** Verifies `external_shipment_id` and `external_order_id`; idempotent when already aligned; refuses when manifest exists or identity mismatches. Existing AWB bind/reconcile overwrite guards unchanged.
- Regression: `HardwareFulfilmentP5ShipmentTest` reconcile filter (**9/9** PASS). Prompt **RadiumDesk-P-25-09-110**.
- Rollback target: v4.0.155 / `d5ffc924`.

## 4.0.155 — 2026-09-28 — GraceExpired validation-failure shift-admin fallback

- **Assignment:** When automation grace expires with serial validation failure and Support round-robin has no eligible agents, assign the configured shift-admin fallback (`ReadyQueueAdmin` / `AfterHoursSupport`) instead of leaving the case permanently unassigned.
- Reuses the existing capability fallback resolver and `assignment.day_shift_admin_user_id` configuration; does not weaken serial validation or normal Support workforce eligibility rules.
- Audit event: `assignment.grace_expired_validation_failed_fallback` with override reasons `grace_expired_validation_failed_shift_admin` / `grace_expired_validation_failed_after_hours_shift_admin`.
- **Preserved:** Validation-success Ready Queue path, validation-failure Support RR when agents are available, intake/missed-call fallback, and safe unassigned behavior when fallback is disabled.
- Regression: `GraceExpiredValidationFailedAssignmentFallbackTest` (**8/8**), assignment regression suite (**41/41**), `SerialValidationTest` (**4/4**) PASS. Prompt **RadiumDesk-P-25-09-106**.
- Rollback target: v4.0.153 / `cca1a6e2` (production pre-release) or v4.0.154 / `fe1acd9b` (includes AST300 SKU mapping only).

## 4.0.154 — 2026-09-28 — RadiumBox hardware SKU mapping (RBP556 AST300 L1)

- Add Owner-approved radiumbox.com `channel_sku_maps` seed configuration for `model_id` **1412** (`PAAST300L1` → `RBAST300L1`).
- Resolves **Product mapping required** for hardware fulfilment order RBP556 and other Box orders carrying AST300 L1 `model_id` 1412.
- Production requires `php artisan desk:seed-radiumbox-hardware-sku-maps --apply` after deploy (separate Owner gate).
- Regression: `SeedRadiumboxHardwareSkuMapsCommandTest`, `RadiumboxHardwareSkuMapResolutionTest` (**12/12** PASS). Prompt **RadiumDesk-P-25-09-104**.
- Rollback target: v4.0.153 / `cca1a6e2`.

## 4.0.153 — 2026-09-28 — Central Wallet M2 account-link confirm API (inert; flags OFF)

- **M2 Desk confirm endpoint:** `POST /api/central-wallet/v1/account-links/{link_id}/confirm` binds Box-verified OTP ceremony to Desk-authoritative account-link confirmation.
- **Verification method binding:** Rejects confirm when `verification_method` does not match the pending link (fail-closed).
- **Owner-approved M2 definition:** authenticated RadiumBox session + explicit Connect Wallet intent + single Interakt WhatsApp OTP + server-side verification + Desk confirm — **no second OTP channel**.
- **Feature flags OFF by default:** `CENTRAL_WALLET_ENABLED`, `CENTRAL_WALLET_API_ENABLED`, and `CENTRAL_WALLET_RECONCILIATION_ENABLED` remain `false`. No account linking enablement in this release.
- **No activation in this release:** No deploy, production migrations, shadow projection, reconciliation runs, or customer-visible Central Wallet behavior.
- **Preserved:** v4.0.152 Central Wallet foundation, existing `users_wallet` ledger, Desk wallet refund credit/reversal, statutory refund adjustment (flagged OFF), rd-orders, and Cashfree-only checkout behavior unchanged.
- Regression: Central Wallet API **17/17**, wallet refund execution **16/16**, broader Central Wallet|WalletRefund filter **100/100** PASS; Pint on changed files PASS.
- Rollback target: v4.0.152 / `e4f115bc`.
- Prompt **RadiumDesk-P-25-09-101** (isolated release branch construction gate).

## 4.0.152 — 2026-09-28 — Central Wallet Phase 1 foundation (inert; flags OFF)

- **Central Wallet hub (Desk):** Phase 1 foundation module under `app/CentralWallet/` — UUID v4 CWID, account links, append-only ledger, 90-day idempotency, audit events, reconciliation scaffold, and integration API v1 routes.
- **Desk-R4.a read API:** Read-only `GET` ledger query endpoints with caller isolation (`source_system === X-Site-Code`), keyset pagination, and filters for RadiumBox shadow reconciliation.
- **Feature flags OFF by default:** `CENTRAL_WALLET_ENABLED`, `CENTRAL_WALLET_API_ENABLED`, and `CENTRAL_WALLET_RECONCILIATION_ENABLED` default to `false`. API middleware is fail-closed (503) while disabled; scheduled reconciliation and idempotency purge jobs are gated off.
- **No activation in this release:** No wallet balance migration, account auto-linking, shadow projection, reconciliation runs, spoke checkout wiring, or customer-visible Central Wallet behavior.
- **Migrations (additive):** `central_wallet_*` foundation tables (7 tables) + ledger read indexes. **Not production-run in this release gate.**
- **Preserved:** Existing `users_wallet` ledger, Desk wallet refund credit/reversal, idempotency, authentication, rd-orders, and Cashfree-only checkout behavior unchanged.
- Regression: Central Wallet **47/47**, R4.a read API **13/13**, wallet/refund regression **48/48** (`--filter=WalletRefund`) PASS; Pint + `npm run build` PASS.
- Rollback target: v4.0.151 / `7d565746`.
- Prompt **RadiumDesk-P-25-09-97** (merge + release-prep gate).

## 4.0.151 — 2026-09-27 — Full refund statutory adjustment (feature-flagged OFF)

- **Post-refund statutory adjustment (v1):** On `RefundCompleted`, enqueue asynchronous statutory adjustment for **completed full refunds only** via `refund_statutory_adjustments` + outbox → `RefundStatutoryAdjustmentService` → existing `StatutoryInvoiceCancellationOrchestrator`.
- **Eligibility guards:** Requires linked issued tax invoice, cumulative full-refundable amount, idempotent skips for partial refunds, POS boundary, already-cancelled invoices, and existing credit notes.
- **Refund safety:** Refund completion remains financially successful even if statutory processing fails; IRN/CN work is outside the refund transaction.
- **Feature flag OFF by default:** `refunds.statutory_adjustment.enabled` / `REFUNDS_STATUTORY_ADJUSTMENT_ENABLED` defaults to `false`. **Not production-enabled in this release.**
- **Migration:** additive `refund_statutory_adjustments` table only.
- **Excluded:** partial-refund automatic CNs, POS automatic adjustment, historical September backfill, live WhiteBooks/NIC verification.
- Regression: `OrderStatutoryInvoiceResolverTest`, `RefundStatutoryAdjustmentTest`, refund/statutory/CN suites (**105** focused tests PASS).
- Rollback target: v4.0.150 / `10ffad34`.
- Prompt **RadiumDesk-P-25-09-79**, release gate **P-25-09-80**.

## 4.0.150 — 2026-09-27 — Refund reference and execution ID separation

- **System-generated refund references:** Canonical `reference_no` remains allocated by `RefundReferenceService`; create requests reject manual `reference_no` overrides.
- **Execution input guards:** `RefundExecutionInputGuard` blocks Desk `REF-*` values from being submitted as `execution_reference_no` or `execution_transaction_id` on manual completion.
- **Wallet execution:** Manual execution reference/transaction inputs are stripped for wallet-approved refunds; provider-generated wallet transaction IDs and payout references remain authoritative.
- **UI clarity:** Execute and revoke panels distinguish Desk refund reference, wallet transaction ID, and external payout reference.
- **No migration.** No wallet/Cashfree executor changes. Historical refund records unchanged.
- Regression: `RefundExecutionReferenceGuardTest` + focused refund/wallet/revoke suites (**60** focused tests PASS).
- Rollback target: v4.0.149 / `aa1cb053`.
- Prompt **RadiumDesk-P-25-09-71**.

## 4.0.149 — 2026-09-27 — Cashfree refund GET lookup

- **Read-only PG API:** `CashfreeApiClient::getOrderRefunds()` and `getRefund()` add GET-only refund lookup for Cashfree orders; shared list parsing with existing payment lookup; `isPgApiConfigured()` separates PG API credentials from webhook HMAC config.
- **No refund execution:** No POST/PUT/PATCH/DELETE provider calls; no Desk refund mutations.
- Regression: `CashfreeApiClientRefundLookupTest` + existing Cashfree config tests (**20** focused tests PASS).
- Rollback target: v4.0.148 / `4340a93b`.
- Prompt **RadiumDesk-P-25-09-61**.

## 4.0.148 — 2026-09-27 — Refund execution-method re-route

- **Controlled re-route:** `RefundExecutionMethodRerouteService` adds an explicit audited action to move `pending_execution` wallet-approved refunds to `approved_refund_method=cashfree` when no automated wallet destination exists for the order source (e.g. legacy `rdservice.net` rows like REF-67329).
- **Permission:** `refunds.reroute_execution_method` granted to admin, operations_admin, and superadmin only; dedicated ops panel on refund show. No provider/API calls during re-route.
- **Safety:** Requires original payment evidence; idempotent when already Cashfree; amount/order/payment state unchanged; audit event `refund.execution_method_rerouted` records previous/new method, operator, reason, order/refund references, and original payment reference.
- **Post re-route:** `RefundExecutorResolver` selects `ManualRefundExecutor` for Cashfree completion (manual UTR/txn attestation; no Cashfree API).
- **Preserved:** v4.0.147 `rdservice.net` wallet approval guard and supported wallet routing for `rdservice.in` / `radiumbox.com`.
- **No migration.** Seeder adds permission only.
- Regression: `RefundExecutionMethodRerouteTest` + wallet guard suites (**22** focused reroute/guard tests PASS).
- Rollback target: v4.0.147 / `3c9fa39e`.
- Prompt **RadiumDesk-P-25-09-65**.

## 4.0.147 — 2026-09-27 — rdservice.net wallet-approval guard

- **Refund approval guard:** `WalletRefundDestinationResolver` rejects `approved_refund_method=wallet` for `rdservice.net` orders (RN/RA/RNP) at review time with a clear validation error; Cashfree and other supported payout methods remain available.
- **Execution fail-closed preserved:** No `RdServiceNetWalletRefundClient`; unsupported wallet execution returns an explicit message that no automated wallet-credit destination is configured and the refund must use a supported payout method.
- **Supported wallet routing unchanged:** `rdservice.in` → `RdServiceInWalletRefundClient`; `radiumbox.com` → `RadiumBoxWalletRefundClient`.
- **No migration.** Does not execute, approve, delete, or mutate REF-67329, REF-67347, or any production refund records.
- Regression: `WalletRefundRdServiceNetApprovalGuardTest`, wallet destination resolver, and wallet execution suites (**32** focused guard tests PASS).
- Rollback target: v4.0.146 / `fbb696bd`.
- Prompt **RadiumDesk-P-25-09-63**.

## 4.0.146 — 2026-09-27 — Credit note billing-address inheritance

- **Credit note IRN readiness:** `StatutoryBillingStructuredResolver` resolves authoritative structured billing from the original invoice’s commerce/POS/service sources when minting a >24h B2B credit note; fail-closed if IRN-complete billing cannot be snapshotted (prevents `missing_buyer_pin` / `missing_buyer_loc` skips on `CancellationAdjustment` credit notes).
- Regression: commerce fallback (INV-0767138 pattern), POS sale fallback, idempotency, outbox submit path, hardware historical duplicate beyond-window CN.
- Prompt **RadiumDesk-P-25-09-59**.

## 4.0.145 — 2026-09-27 — Statutory cancellation workflow and credit note foundation

- **Unified cancellation orchestrator:** Canonical policy-driven statutory cancellation for Finance, POS, and historical duplicate fulfilment paths with idempotency and audit trail.
- **B2B IRN &lt;24h:** EWB stub → IRN cancellation (fail-closed) → original invoice **Cancelled**; no credit note.
- **B2B IRN &gt;24h:** Original invoice remains **Issued**; GST credit note issued and linked; CN IRN queued; CA Monthly nets credit-note amounts.
- **B2C:** Local invoice cancellation without IRN workflow.
- **Historical duplicate protection:** Hardware historical duplicate cancellation routes through the orchestrator; regression coverage for IRN age and provider-failure paths.
- **Credit note foundation:** `original_statutory_invoice_id` migration + DB unique constraint (one CN per original); application idempotency and race handling.
- **WhiteBooks CANCEL adapter:** `POST /einvoice/type/CANCEL/version/V1_03` integration (code-derived; **live contract not verified**).
- **CN IRN payload:** CRN mapper, `RefDtls`, and eligibility fixtures (**live CRN GENERATE not verified**).
- **Migrations:** `2026_09_26_220000_add_original_statutory_invoice_id_to_statutory_invoices`, `2026_09_26_230000_add_unique_credit_note_original_statutory_invoice_id`.
- **Provider configuration unchanged by this release:** `STATUTORY_EINVOICE_PROVIDER` remains `none` until a separate operational gate enables WhiteBooks. With provider `none`, B2B IRN-required cancellations fail closed (no local cancel).
- Regression: statutory cancellation gate suite (**195** tests), CA Monthly paired original+CN netting, WhiteBooks CANCEL `Http::fake` coverage, historical duplicate IRN regressions.
- Rollback target: v4.0.144 / `d0323e79`.
- Prompts **RadiumDesk-P-25-09-47** through **P-25-09-51**.

## 4.0.144 — 2026-09-26 — Deprecate legacy deployed-commit release marker

- **Release identity:** Document that `storage/app/private/release.json` is the sole authoritative deployed release manifest on KVM. `storage/app/deployed-commit.txt` is deprecated legacy metadata and must not be used by operators.
- **KVM deploy:** Post-deploy cleanup removes orphaned `storage/app/deployed-commit.txt` if present (does not sync or recreate it).
- Rollback target: v4.0.143 / `fdf5e57f`.
- Prompt **RadiumDesk-P-25-09-44**.

## 4.0.143 — 2026-09-26 — CA Monthly header-discount reconciliation

- **CA Monthly preflight:** Invoice-grain reconciliation now subtracts statutory header `discount` from the identity check (`taxable − discount + shipping + IGST + CGST + SGST + rounding = invoice_value`). Fixes false non-reconciling preflight for POS invoices such as INV-076768 where header discount bridges line gross to invoice total. Reporting/export 22-column contract unchanged; no statutory invoice mutations.
- Regression: CA Monthly suite (**148** tests incl. header-discount and rounding preflight cases); 22-column Payment Channel contract preserved.
- Rollback target: v4.0.142 / `1851472a`.
- Prompt **RadiumDesk-P-25-09-43**.

## 4.0.142 — 2026-09-26 — AST300 rdservice.in zero-value statutory invoice prevention

- **Root cause fix:** AST300 L1 paid rdservice.in renewals with Cashfree evidence but support-only commerce ingest (`RD Technical Support — included` at ₹0) now repair into an authoritative billable service line before statutory mint, using existing inclusive GST (`GstSplitService`) and product metadata (`rd_service_name`, serial, AST300 identity).
- **Ingest guard:** Paid rdservice.in channel ingest rejects support-only zero-value payloads at validation; unpaid support-only payloads remain accepted but not invoice-eligible.
- **Mint protection preserved:** `NO_BILLABLE_LINES` gate unchanged — included-only orders stay ineligible; repaired paid AST300 orders become eligible only after billable commerce lines exist.
- **Idempotent:** Repeated ingest/repair/mint does not duplicate billable lines, statutory invoices, or companion support rows; commerce orders already linked to a statutory invoice are not repaired.
- **No migration.** Does not alter the nine historical corrected invoices (P-25-09-41), payment records, or customer identity.
- Regression: new `RdServiceInAst300CommerceSnapshotTest` (**14** scenarios), channel ingest guard tests, statutory mint/GST/hardware/RadiumBox protected suites, CA Monthly export channel checks; Pint + build PASS.
- Rollback target: v4.0.141 / `2bb2e8c7`.
- Prompt **RadiumDesk-P-25-09-42**.

## 4.0.141 — 2026-09-26 — CA Monthly XLSX Excel Desktop compatibility

- **Excel Desktop fix:** CA Monthly XLSX export now packages a complete OOXML workbook for Microsoft Excel Desktop — adds minimal `xl/styles.xml`, workbook `bookViews`, worksheet `dimension`, valid `pageSetup`, and correct content-type/relationship wiring. Fixes production export unreadable-content failure on full-period workbooks (e.g. export #11, 7264 invoice rows).
- **Package validation:** New `CaMonthlyReportXlsxPackageValidator` runs after every XLSX write; generation fails fast if Excel-required parts are missing.
- **Contract preserved:** 22-column CA Monthly register unchanged (Payment Channel only; no Payment Method / Payment Reference). Reporting period subtitle, payment-channel logic, cancelled-invoice inclusion/exclusion, and all statutory row values unchanged.
- **No migration.** XLSX writer, validator, tests, and docs only.
- Regression: CA Monthly suite (**141** tests incl. XLSX compatibility), payment channel / Partial Paid tolerance / cancellation / GST / identity protected suites unchanged vs v4.0.140 baseline.
- Rollback target: v4.0.140 / `adda4806`.
- Prompts **RadiumDesk-P-25-09-34** through **P-25-09-36**.

## 4.0.140 — 2026-09-26 — CA Monthly Partial Paid tolerance and payment column simplification

- **Partial Paid rule:** Inclusive ₹1.00 tolerance — differences at or below ₹1.00 are treated as fully paid; actual channel (CF / HDFC M / HDFC D / Cash) retained when determinable. Fixes INV-67642, INV-67643, and INV-67506 (₹0.01 difference → **CF**, not Partial Paid).
- **CA export contract (22 columns):** Payment Channel only; Payment Method and Payment Reference removed from CA-facing XLSX/preview (underlying data and internal resolvers unchanged).
- **No migration.** Reporting layer, views, docs, and tests only.
- Regression: CA Monthly suite (**131** tests), statutory cancellation/GST/reconciliation/identity protected suites unchanged vs v4.0.139 baseline.
- Rollback target: v4.0.139 / `061970bc`.
- Prompts **RadiumDesk-P-25-09-31** through **P-25-09-32**.

## 4.0.139 — 2026-09-26 — CA Monthly reporting corrections (branch, status, payment channel, goods)

- **CA Monthly export contract (24 columns):** Branch normalization (`radium_delhi` / `DELHI-RETAIL` → `Delhi`; blank when no authoritative source); **Status** (Issued / Cancelled / Credit Note); Order Type **Goods** for POS hardware; **Payment Channel** (`CF`, `HDFC M`, `HDFC D`, `Cash`, `Unpaid`, `Partial Paid`) with Payment Method and Payment Reference retained for reconciliation.
- **Reporting period:** Filter remains `statutory_invoices.issued_at`; XLSX subtitle displays `Reporting period (invoice issue date): DD-MMM-YYYY to DD-MMM-YYYY`.
- **Cancelled invoices:** Included at invoice grain with original values and Status = Cancelled; excluded from summary totals only.
- Preserves exact statutory invoice amounts (no cosmetic rounding); does not modify statutory financial records, product/category master data, or production v4.0.138 behavior.
- **No migration.** Reporting layer, views, docs, and tests only.
- Regression: CA Monthly suite (**114** tests incl. corrections + payment channel), statutory reconciliation/GST/identity protected suites unchanged vs v4.0.138 baseline.
- Rollback target: v4.0.138 / `24542963`.
- Prompts **RadiumDesk-P-25-09-25** through **P-25-09-29**.

## 4.0.138 — 2026-09-26 — CA Monthly Report preflight, export hardening, and Super Admin download audit

- **CA Monthly Report (Gate 1):** Super Admin-only async preflight endpoint; ordinary Admin page load skips the full preflight scan; non-reconciling invoice diagnostics preserved (not suppressed); download readiness gating until preflight/export complete; XLSX cell XML sanitization (`CaMonthlyReportXmlCellEncoder`) and DOM validation before packaging.
- **CA Monthly Report (Gate 2):** Super Admin-only paginated **Report Download History**; sync quick downloads create `ca_monthly_report_exports` records; `audit_logs` event `ca_monthly_report.download_response_initiated` records user identity, timestamp, reporting period, format, row count, export ID, generation duration, and status without duplicate polling noise or false positives on failed exports.
- **Login security:** Hide What's New modal and version footer from unauthenticated guest layout (`guest.blade.php`); regression in `PlatformIdentityTest`.
- Preserves v4.0.137 service statutory GST B2B/B2C issuance rule, reconciliation backlog fix, customer identity protection, sale-time buyer snapshot, IRN/idempotency, Finance manual invoice, and checkout/payment behavior.
- **No migration.** Code, views, routes, config, and tests only.
- Regression: `CaMonthlyReportTest` + export/reconciliation/XML encoder suites (**91** focused CA Monthly tests), `PlatformIdentityTest` login metadata gate, protected statutory/GST/identity suites unchanged vs v4.0.137 baseline.
- Rollback target: v4.0.137 / `bea9a28f` (application); production overlay rollback restores pre-overlay backup `p-25-09-22-ca-monthly-20260926T071705Z`.
- Prompts **RadiumDesk-P-25-09-21** through **P-25-09-23**.

## 4.0.137 — 2026-09-26 — Service statutory GST B2B/B2C issuance rule

- At service statutory mint, classify paid orders with valid GSTIN and consistent billing state as B2B; invalid, incomplete, or state-mismatched GSTIN issues immediate B2C via the existing mint path (no customer verification workflow).
- Customer 360 shows a concise note on issued GST-based B2C invoices (`GSTIN invalid`, `GSTIN incomplete`, or `GSTIN/state mismatch`).
- Preserves v4.0.136 service statutory reconciliation backlog fix, hardware serial immutability, Finance manual issue, IRN/idempotency, and checkout/payment behavior.
- **No migration.** Code and tests only. Does not auto-remediate historical B2B GSTIN/state mismatches (e.g. RD993, RD2304, RD3646, RD4070, RD6277).
- Regression: `ServiceStatutoryInvoiceReconciliationBacklogTest` (13), `ServiceStatutoryGstB2bB2cIssuanceTest` (6), `ServiceStatutoryIssuanceTest` (31). Prompts **RadiumDesk-P-25-09-15** through **P-25-09-17**.
- Rollback target: v4.0.136 / `b35e8ca8`.

## 4.0.136 — 2026-09-25 — Service statutory invoice reconciliation backlog fix

- Fix reconciliation starvation caused by a static first-100 candidate row limit: scan the full online-service candidate set with a mint-attempt budget instead of capping the query at 100 rows.
- Narrow reconciliation candidates at SQL level to online service source ids and exclude `hardware_fulfilment` rows so hardware/non-service backlog does not consume scan budget.
- Clarify `batch_limit` as maximum mint attempts per scheduler run; add `max_scan_per_run` safety bound (default 10,000).
- Resolve rdservice.net RA/RN service orders to the correct commerce channel when minting via support-order reconciliation.
- Preserve B2B GSTIN/state validation, hardware serial immutability, Finance manual issue, IRN/idempotency, and v4.0.135 customer identity behavior.
- **No migration.** Code and config only. Does not auto-remediate B2B GSTIN/state mismatches (e.g. RD993, RD2304).
- Regression: `ServiceStatutoryInvoiceReconciliationBacklogTest` (13), statutory mint retry, Finance manual issue, customer identity, issuance, and hardware serial gate suites.
- Rollback target: v4.0.135 / `74bfbaa5`.

## 4.0.135 — 2026-09-25 — POS customer identity conflict handling

- Block silent legal-identity overwrite when an existing customer phone matches a different company name or GSTIN at POS checkout.
- Require explicit `sale_only` or `update_master` resolution before completing a conflicting sale.
- Snapshot `buyer_name` on `inventory_sales` for sale-time buyer identity; historical POS views use statutory fallback when `buyer_name` is absent (INV-076769 regression).
- Preserves v4.0.134 POS unpaid/payment-pending workflow, payment reconciliation, statutory invoice generation, and PDF layout.
- Additive migration only: nullable `inventory_sales.buyer_name`.
- Regression: `PosCustomerIdentityTest` (12), resolver unit tests, POS unpaid workflow suites.
- Rollback target: v4.0.134 / `a9e90519`.

## 4.0.134 — 2026-09-24 — Payment reconciliation and allocation-backed payment status

- Add cancellation → refund review bridge (read-only/explicit; no automatic Wallet/OPM refund).
- Add canonical `customer_payments` / `payment_allocations` evidence for Desk POS and Service POS with allocation-backed payment status on Finance and statutory PDFs.
- Add historical POS payment reconciliation scope (Desk POS + Inventory Sale + issued from 2026-09-01) with Admin-only backfill workflow, audit/idempotency, and `finance.invoices.payment_backfill` permission.
- Preserves `SimplePdfRenderer` layout (A4, serials, annexure, footer), cancellation orchestrator, Wallet/OPM refund executors, service statutory reconciliation, and protected invoices INV-0767292 (6347) / INV-0767294 (6397).
- **No automatic historical backfill on deploy.** Admin backfill is explicit-only.
- Regression: payment/backfill/cancellation/refund/PDF suites (152+ focused tests). Prompts **RadiumDesk-P-23-09-48** through **P-23-09-50**, **P-23-09-53**.
- Rollback target: v4.0.133 / `2e436f9b`.

## 4.0.133 — 2026-09-24 — Statutory invoice cancellation orchestrator

- Add `StatutoryInvoiceCancellationOrchestrator` as the canonical Finance/Admin cancellation entry point with idempotency, audit trail, IRN pre-check (fail-closed), and linked DeskPos inventory/finance reversal via `PosSaleService::cancelSale()`.
- Add Finance invoice show cancel UI (`finance.invoices.cancel` permission) with mandatory reason and confirmation.
- Add additive migration `statutory_invoice_cancellations` for cancellation persistence/idempotency.
- Credit-note minting intentionally **not** implemented (current-release policy §23.4). WhiteBooks IRP cancellation intentionally **not** implemented (fail-closed when submitted IRN exists).
- Preserves v4.0.132 PDF layout (A4 footer, measured serials, annexure, logo spacing), service statutory retry/reconciliation, GST guards, POS/hardware cancellation paths, and INV-0767292 presentation.
- Regression: `StatutoryInvoiceCancellationOrchestratorTest` (14), existing cancellation/statutory suites. Prompt **RadiumDesk-P-23-09-44** / **P-23-09-45**.
- Rollback target: v4.0.132-logo-seller-gap / `894ee39e`.

## 4.0.128 — 2026-09-23 — Zero-tax-line-safe intra-state CGST/SGST allocation

- Harden `IntraStateCgstSgstRules::allocateLineHalfPaise` so invoice-level ±1 paise adjustments apply only to eligible positive-tax lines; zero-tax/zero-value lines remain exactly zero and no CGST/SGST component can become negative.
- Fail closed via existing `assertMintSnapshot` when the target header half cannot be reached safely under these constraints.
- Add governed snapshot remediation commands for historical B2C (70), B2B IRP-2227 (6), and INV-2767116/RD3787 corrections (production data already remediated under P-23-09-18/20/21; **this release does not re-run remediation**).
- Preserves v4.0.127 invoice-level CGST/SGST reconciliation, v4.0.126 RD Service 1-paisa tolerance, RBP415 hardware inclusive GST, e-invoice guards, and all corrected production snapshots.
- Regression: `IntraStateCgstSgstRulesTest`, `GstSplitServiceTest`, `IntraStateCgstSgstIssuanceTest`, `EInvoiceStoredGstGuardIntraStateTest`, remediation unit tests. Prompts **RadiumDesk-P-23-09-18** through **P-23-09-22**.
- Rollback target: v4.0.127 / `b90458f8`.

## 4.0.127 — 2026-09-23 — Statutory eligibility + intra-state CGST/SGST IRP compliance

- Align statutory mint eligibility with billable commerce lines: require ≥1 line with `includesOnStatutoryInvoice()`; GST pre-check runs on billable lines only; four zero-billable support-only orders (RD3513756, RD2916, RD3162, RD7478) fail eligibility instead of mint.
- Close intra-state B2B CGST/SGST multi-line drift: invoice-level `allocateLineHalfPaise` reconciles line ideals to authoritative `tax_total` (NIC IRP 2227/2234); header CGST == header SGST == sum(line halves); max 1-paise invoice-level drift (non-cumulative).
- Fail-closed `EInvoiceStoredGstGuard` and pre-persist `assertIntraStateMintTotals` on stored statutory snapshots; IRP mapper remains read-only.
- Preserves v4.0.126 RD Service 1-paisa tolerance, RBP415 hardware inclusive GST, IGST/inter-state, B2C, and all historical invoice/e-invoice snapshots. **No historical remediation.**
- Regression: `StatutoryMintEligibilityBillableLinesTest`, `IntraStateCgstSgstRulesTest`, `GstSplitServiceTest`, `IntraStateCgstSgstIssuanceTest`, `EInvoiceStoredGstGuardIntraStateTest`, and related statutory suites (157/159 PASS; 2 pre-existing UQC). Prompts **RadiumDesk-P-23-09-12** through **P-23-09-14**.
- Rollback target: v4.0.126 / `8ae150e4`.

## 4.0.126 — 2026-09-23 — rdservice.in RD Service 1-paisa GST tolerance

- Allow exactly **1 paisa** exclusive GST identity tolerance for `rdservice_in` **RD*** service orders with commercial date on/after **2026-09-01**, via consolidated `PublishSellingExclusiveGstTolerance` (publish-minus-selling inclusive catalog splits).
- Preserves fail-closed behaviour for >1 paisa; does not apply to RDP/RIN hardware, does not use hardware inclusive reconciler, does not rewrite commerce rows.
- Regression: `RdServiceInPublishSellingGstToleranceTest`, `ServiceGstSplitIssuanceTest`. Prompt **RadiumDesk-P-23-09-06**.
- Rollback target: v4.0.125 / `55ccd76c`.

## 4.0.125 — 2026-09-23 — RD Service statutory display name (effective 2026-09-01)

- Canonical RD service statutory line description **IT Consulting & Support Service** for SAC **998313**, applied only when commerce commercial date is on/after **2026-09-01** (`ordered_at` → `paid_at` → `received_at` via `StatutoryMintEligibility::commercialDate()`).
- Strip embedded `(SAC - 998313)` from Item description at new-mint presentation only; SAC remains in the dedicated HSN/SAC column.
- Service POS catalog display and quote defaults for `rd_service` + SAC 998313 unchanged.
- Does not rewrite `commerce_order_items.description`, existing `statutory_invoice_items`, or stored PDF snapshots. Prompt **RadiumDesk-P-23-09-03**.
- Rollback target: v4.0.124 / `1e283b93`.

## 4.0.124 — 2026-09-23 — RadiumBox hardware SKU mapping (RBP447/RBP449)

- Add Owner-approved radiumbox.com `channel_sku_maps` seed configuration for `model_id` **625** (`PMTMFS500Z` → `RBMFS500FP`) and **1420** (`PIDMORUCBL` → `RBMSOUSBCB`).
- Resolves **Product mapping required** for hardware fulfilment orders carrying these Box model IDs (e.g. RBP449, RBP447).
- Production requires `php artisan desk:seed-radiumbox-hardware-sku-maps --apply` after deploy.
- Regression: `SeedRadiumboxHardwareSkuMapsCommandTest`, `RadiumboxHardwareSkuMapResolutionTest`. Prompt **RadiumDesk-P-23-09-02**.
- Rollback target: v4.0.123 / `5473288b`.

## 4.0.123 — 2026-09-23 — Hardware inclusive GST multi-qty reconciliation (RBP415)

- Fix `HardwareInclusiveGstReconciler` to project invoice GST from authoritative line gross when stored taxable + tax exactly equals gross but exclusive identity drifts beyond one paisa after multi-qty accumulation (e.g. RBP415 qty 12 @ ₹3048).
- Preserves fail-closed behaviour when gross does not reconcile to stored taxable + tax; commerce rows are not rewritten.
- Regression: `HardwareInclusiveGstReconcilerTest`, `HardwareInclusiveGstInvoiceTest` (RBP415 fixture). Prompt **RadiumDesk-P-23-09-01**.
- Rollback target: v4.0.122 / `935ce1f6`.

## 4.0.122 — 2026-09-22 — CA Monthly Report invoice-level register

- Redesign CA Monthly Report export from 27-column line-grain dump to a 21-column invoice-level accounting register with expandable XLSX line detail (9 detail columns).
- Parent rows use authoritative `statutory_invoices` totals (taxable, shipping, IGST/CGST/SGST, short/excess, invoice total); CSV exports parent rows only.
- Branch resolution: invoice branch → inventory sale branch → commerce `branch_code` (no GSTIN/state inference).
- Payment mode resolution: hardware evidence → support order → commerce → allocation → invoice snapshot; provider aliases (e.g. cashfree, payu) filtered.
- Exclude zero-value included support lines from expandable detail; remove Status and Document Type from export/preview.
- XLSX workbook: title/period rows, frozen header, AutoFilter, outline-grouped hidden child rows, summary totals.
- Regression: `CaMonthlyReportInvoiceRegisterTest` + updated `CaMonthlyReportTest` / `CaMonthlyReportExportTest` (70 CA report tests). Traceability: `radiumbox.com-P-22-09-09`, `RadiumDesk-P-22-09-28`. **No database migration.**
- Rollback target: v4.0.121 / `dd83acc6`.

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
