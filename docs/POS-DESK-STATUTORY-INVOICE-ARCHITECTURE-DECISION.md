# POS + Desk Statutory Invoice Architecture Decision

> **Canonical status (RadiumDesk-P-05-09-08):** POS remains the till plus internal `INV-{branch}-{year}-{seq}` receipt. Finance Hub is the only GST issuer. Statutory numbers are `INV-{GST_STATE}{FY}{SERIAL}` (FY26–27 Delhi `INV-07671`, Mumbai `INV-27671`). Product issuer is branch-based; service issuer is B2B/B2C + customer state. See `docs/desk-statutory-location-numbering.md`.

**Project:** New Admin / Radium Desk  
**Repository:** `/Users/ravi/RadiumWebsites/radium-desk`  
**Prompt ID:** RadiumDesk-P-02-09-02  
**Date:** 2026-09-02  
**Type:** Architecture decision record. No application-code change. No mint. No production DB. No deploy. No commit.  
**Canvas:** [`pos-desk-statutory-invoice-architecture-decision.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-RadiumWebsites-radium-desk/canvases/pos-desk-statutory-invoice-architecture-decision.canvas.tsx)

**Status:** Architecture approved. Phase **P1** (durable pending-invoice work that does not mint) is implemented in RadiumDesk-P-02-09-03.

**Confirmed issuance (RadiumDesk-P-03-09-10):** Statutory tax invoices are issued manually by authorized Admin users from Radium Desk. Automatic statutory invoice issuance is OFF. Background workers do not mint. GSTIN → state → FY → sequence. Branch → GSTIN → shared statutory series.

Classification: **DECIDED** (owner, this ticket) · **VERIFIED** (this worktree) · **INFERRED** · **UNKNOWN** (CA/legal or not yet in source).

Related:

- `docs/POS-DESK-STATUTORY-INVOICE-READINESS-AUDIT.md` (P-02-09-01) — topology/issuer were **open**; this record closes them
- `docs/CENTRAL-STATUTORY-ENGINE-READINESS-AUDIT.md` (P-01-09-18)
- `docs/rd-central-finance-invoice-architecture.md` (P-01-09-14–17)

This document does **not** invent GST split rules, legal series, FY tokens, or GSTIN values.

---

## Inspect (this ticket)

| Item | Value | Class |
|------|-------|-------|
| Repository | `/Users/ravi/RadiumWebsites/radium-desk` | VERIFIED |
| Ledger | `docs/cursor-prompt-ledger.md` last row P-02-09-01 | VERIFIED |
| This ID | **RadiumDesk-P-02-09-02** (next unused) | VERIFIED |
| Branch | `feat/rd-fresh-01-inventory-pos` | VERIFIED |
| HEAD | `98c2c5e4faa92535df276d189da5d57b36990577` | VERIFIED |
| Worktree | P-02-09-01 docs uncommitted (`docs/POS-DESK-STATUTORY-INVOICE-READINESS-AUDIT.md`, ledger) | VERIFIED |
| Remote | `origin` = `git@github.com:radium-foundation/radium-desk.git` | VERIFIED |
| `origin/main` | Statutory/ingest commits **not** ancestors | VERIFIED |
| Production DB | Not connected | UNKNOWN by design |

Technical re-check of current source (no behaviour change):

| Fact | Evidence | Class |
|------|----------|-------|
| Physical POS is in-process Desk | `PosSaleService::completeSale` writes `inventory_sales` in this app | VERIFIED |
| Sale persistence | One DB transaction: sale, lines, serials, internal `INV-*`, `pos_sale` journal | VERIFIED |
| Internal receipt ≠ GST | Blade titled Internal receipt; formatter rejects `INV-[A-Z0-9]+-\d{4}-\d{5}` as statutory | VERIFIED |
| Canonical mint engine | `StatutoryInvoiceService::mint` / `issueFromPosSale` | VERIFIED |
| Fail-closed auto-issue | `auto_issue_on_pos_complete` and `channel_ingest.auto_issue_invoice` hard-false; enabling them **rejects** sale/ingest today | VERIFIED |
| Numbering lock + uniqueness | `lockForUpdate` on sequence; unique `invoice_number`, unique source triple, unique idempotency key | VERIFIED |
| POS mint key | `statutory:desk_pos:inventory_sale:{id}` via `StatutoryInvoiceMintRequest::sourceKey` | VERIFIED |
| HTTP ingest denies POS | Authenticator + payload `httpChannels()`; test `test_desk_pos_cannot_use_the_http_ingest_api` | VERIFIED |
| Website ingest exists | `POST /api/v1/channel-orders`; stores `commerce_orders`; never mints | VERIFIED |
| Outbox infrastructure | `outbox_events` + `outbox:process`; handlers for Cashfree/Interakt/BonVoice/email only; **POS does not write it** | VERIFIED |
| `issueFromPosSale` callers | Tests only; no controller/listener | VERIFIED |

---

## 1. Decision

**Owner-approved target (DECIDED this ticket):**

1. Physical POS is part of Radium Desk (in-process). **Do not** add a POS HTTP channel merely because online spokes use HTTP.
2. Radium Desk is the **sole authoritative issuer of NEW statutory/GST invoices**. There is one engine: `StatutoryInvoiceService`. Do not add a second engine. Do not keep Old Admin as a future issuer.
3. POS **sale/payment success must not depend synchronously** on statutory numbering, PDF, or IRN. After the sale commits: durable pending work → background worker → mint → document. Temporary issuance failure leaves **Sale = SUCCESS, Invoice = PENDING**, then retry until ISSUED.
4. Online spokes remain independently operational: local paid order + durable outbox → Desk HMAC ingest → **same** engine.
5. Historical invoices are never renumbered, rewritten, or deleted.
6. Automatic statutory issuance stays **OFF** until a later explicit production-readiness gate.

This closes the P-02-09-01 topology question as **Topology A** (in-process POS + async mint) and the P-01-09-18 issuer question as **Desk**, not a separate Finance tax-document service, for this programme.

**Technically valid against current code:** yes, if (and only if) issuance is **after** `completeSale` commits, and the current fail-closed flags are **not** used to mint inside the sale transaction. See §6 and §10.

---

## 2. Context

P-02-09-01 found two possible POS shapes. Current source already matches in-process POS plus a **separate** website ingest that **rejects** `desk_pos`. The prompt diagram that required POS checkout to survive a full Desk platform outage is **not** this POS: a Desk outage **is** a physical-POS outage because they share the process and database.

The owner has now accepted that. The independence requirement that **does** apply:

> Statutory engine failure after a committed sale must not undo or block that sale.

That is different from “POS can take payment while Desk is down.” Physical POS cannot. Online spokes can (local paid order + outbox).

Old Admin remains capable of allocating for some channels in **Admin source** (rdservice.in is gated in Admin tests; Old Admin POS/`invoice_no` still allocates). Cutover of Admin is **out of this repository** and is a later explicit gate. This ADR binds Desk design so that after cutover there is only one new-number issuer.

---

## 3. Physical POS topology

```
Customer
  → Desk POS counter (session, PosAccess, branch scope)
  → PosSaleService::completeSale  [ONE DB TRANSACTION]
        durable inventory_sales (completed)
        internal INV-{branch}-{year}-{seq} receipt
        stock / serials
        pos_sale journal (existing fail-closed GL)
        durable pending-statutory work  (NEW — same txn as sale; see §6)
  → COMMIT  = SALE SUCCESS  (payment/sale does not wait for GST number)
  → background worker
        if auto-issue OFF → no mint; work stays blocked/no-op  (until production gate)
        if auto-issue ON (later gate only) → StatutoryInvoiceService::issueFromPosSale
  → statutory invoice row + allocated number
  → PDF/document job (after number exists)
  → POS show/print displays internal receipt + statutory number/status when present
```

**Do not:**

- Call `POST /api/v1/channel-orders` from POS
- Enable `desk_pos` on HMAC ingest
- Mint inside `completeSale`
- Use the internal `INV-*` string as the GST number
- Flip `statutory_invoices.auto_issue_on_pos_complete` to true **as currently implemented** — that flag **aborts checkout** (`assertMustNotAutoIssueOnPosComplete`)

POS display today: `SaleController::invoice` already eager-loads `statutoryInvoice` and the Blade can show the GST number if linked. No extra HTTP proxy is required for physical POS.

---

## 4. Online spoke topology

```
Website (rdservice.in, radiumbox.com, rdservice.net, radiumsign.com, future)
  → local paid order + local durable outbox   [OTHER REPOS — not this ticket]
  → HMAC POST /api/v1/channel-orders
  → ChannelIngestService (idempotent commerce_orders)
  → durable pending-statutory work for that commerce order  (later; same engine)
  → background worker → StatutoryInvoiceService::mint
  → PDF/document
  → spoke retries / polls status  (GET status is a later Desk endpoint; ingest POST-retry is already idempotent)
```

Desk outage must not prevent **spoke** checkout. That outbox lives **on the spoke**, not in Desk POS.

Current ingest **must keep** `auto_issue_invoice = false` until the production gate. Enabling that flag **today rejects the ingest** rather than minting asynchronously. The approved model is: ingest **always** accepts a valid paid order; mint is a later worker.

Do not create a second statutory engine for spokes. Spoke `source` key:

`statutory:{channel}:commerce_order:{source_id}`

---

## 5. Statutory issuer decision

| Role | Decision |
|------|----------|
| NEW GST / statutory numbers | **Desk only** — `StatutoryInvoiceNumberingService::allocate` inside `StatutoryInvoiceService::mint` |
| Physical POS | In-process call to the **same** mint (`issueFromPosSale`) after sale commit |
| Online spokes | Ingest then **same** mint |
| Old Admin | **Not** a future issuer. Historical/reference/migration source only |
| Separate Finance tax-document service | **Not** the issuer for this programme (supersedes the unresolved placement in P-01-09-18 for Desk work) |
| Internal POS `INV-*` | Operational receipt only — never a statutory number |
| Legal series / FY / GSTIN scope | **UNKNOWN** — CA. Engine already refuses to allocate while unset. Do not invent values |

One engine. Two entry adapters (POS sale projection vs commerce-order projection). Zero local spoke numbering.

---

## 6. Transaction boundary

**Sale transaction (physical POS):**

Must commit independently of statutory allocation and of PDF.

Include in that same sale transaction (classic transactional outbox):

- `inventory_sales` + lines + serials
- internal receipt number
- existing `pos_sale` journal
- **one** pending-statutory outbox/work row keyed by `statutory:desk_pos:inventory_sale:{id}`

After COMMIT, mint runs in a **later** transaction. If mint fails, the sale row remains. If the process crashes after COMMIT but before the worker runs, the pending row is the recovery handle.

**Do not** wrap mint in `completeSale`. Current `assertMustNotAutoIssueOnPosComplete()` encodes “do not mint here”; keep that. A future worker-enable flag must be a **different** config (or a redefined meaning that the sale path never reads for control flow except “enqueue work”).

**Spoke ingest transaction:** persist `commerce_orders` (+ later pending-mint work) without allocating a number. Mint in a later transaction.

**Mint transaction (existing, keep):** allocate sequence + insert `statutory_invoices` + lines + link `inventory_sales.statutory_invoice_id` in **one** DB transaction. Failed mint rolls back the number. Successful mint never reuses that number.

**PDF transaction:** after the issued invoice exists. Failure does not roll back the invoice or the number.

---

## 7. Idempotency model

Canonical POS issuance key (**DECIDED**, already implemented on mint):

```
statutory:desk_pos:inventory_sale:{id}
```

`{id}` is `inventory_sales.id` after insert. Do not use a random request UUID. Do not use the internal `INV-*` receipt. Do not use `sale_no` as the uniqueness key (it is `source_order_id` only).

Retries of mint / worker / lost response return the **same** `statutory_invoices` row and the **same** allocated number. Sequence `current_value` does not increment again. **VERIFIED** in `StatutoryInvoiceServiceTest::test_mint_is_idempotent_for_the_same_channel_source`.

POS **checkout** duplicate-submit remains a separate key: `inventory_sales.idempotency_key`. That protects double-click complete, not GST numbering.

Spoke key remains `statutory:{channel}:{source_type}:{source_id}`. Optional HTTP `Idempotency-Key` must equal that string.

Pending-work row uniqueness: same canonical key (or a 1:1 outbox `idempotency_key` derived from it) so two workers cannot enqueue two mints for one sale.

---

## 8. Numbering model

Keep the existing allocator. Do not replace it.

| Rule | Current source | Keep? |
|------|----------------|-------|
| Refuse if series **or** format empty | `isConfigured()` | Yes — production gate sets CA values; this ADR does not |
| `lockForUpdate` on sequence row | `allocate()` | Yes |
| Unique `allocated_number` and `invoice_number` | migrations | Yes |
| Unique source triple | migrations | Yes |
| Reject POS internal pattern as GST number | formatter | Yes |
| Allocate and insert invoice in one txn | `mint()` | Yes |
| Cancel keeps the number | `cancel()` | Yes — cancelled numbers are **not** reused |
| Per-branch POS `invoice_sequence` | `completeSale` | Yes — **internal receipt only** |

Never reuse a number after successful allocation, including after cancel. Credit notes (when built) get a **separate** series/document type — **UNKNOWN** format until CA.

Two simultaneous POS sales: distinct `inventory_sales.id` → distinct keys → serialize on the sequence row → distinct GST numbers. Concurrent retries of the same sale → one invoice. MySQL two-process proof remains harness-gated (**UNKNOWN** on machines without the disposable MariaDB harness).

Legal prefix, FY reset, and per-GSTIN series: **UNKNOWN**. Do not copy Admin `INV67` / `IND67` / `INS` or POS `INV-*`.

---

## 9. Document-generation model

1. Statutory **record and number** exist first (`status = issued`).
2. PDF/document is generated **after** that commit.
3. If PDF fails: retry PDF only. Do not roll back, delete, or reallocate the number.
4. Store on a private disk object keyed by invoice id/number (**not implemented** today — no `pdf_path`).
5. Physical POS retrieves via existing session routes (`pos.sales.invoice` / future PDF download under `PosAccess` + branch scope). No HMAC POS proxy.
6. Spokes need a later authenticated document/status GET; until then POST-retry of ingest returns `invoice: null` while auto-issue is off, and must return the same invoice id once minted.

IRN / GSP remains a stub (`NullEInvoiceGateway`, not called). Whether B2C POS requires IRN is **UNKNOWN** / CA. Do not block sale success on IRN.

---

## 10. Failure / retry model

| Event | Result |
|-------|--------|
| Sale txn fails | Nothing committed; operator retries checkout |
| Sale txn succeeds; worker down | Sale durable; pending work durable; invoice PENDING |
| Mint fails (series unset, validation, deadlock) | Sale unchanged; pending work retries; **no** number consumed if mint txn rolled back |
| Mint succeeds; caller/worker crashes | Retry mint → same invoice |
| PDF fails after mint | Invoice ISSUED; document job retries |
| Worker retries forever while auto-issue OFF | Must **no-op without minting** (blocked reason), not fail the sale, not increment sequence |
| Enabling current `auto_issue_on_pos_complete` | **Forbidden** — would fail checkout. Replace with post-commit worker enablement at the production gate |

Existing `outbox_events` processor: claim with `lockForUpdate`, attempts, backoff, stale `processing` recovery, unique `idempotency_key`. **VERIFIED.** POS does not use it yet. Reuse is allowed **if** a new `event_type` is added to `OutboxProcessorService::dispatch` (unknown types currently throw). Mixing statutory mint with Cashfree/Interakt drain is an implementation risk (contention); a dedicated pending-invoice table is an acceptable alternative. Either way: write the work row in the **sale** transaction.

No paid POS sale may be silently lost. Uninvoiced is allowed until the production gate; after the gate, uninvoiced must be **visible and retryable**, not dropped.

---

## 11. Old Admin retirement / reference boundary

**Future issuer:** no.

**Allowed uses of Old Admin (read-only unless a later Admin-repo prompt says otherwise):**

- Historical behaviour and business-rule verification
- Migration analysis (counts, MAX(number), relationships)
- Historical invoice **data** import **as-is** into Desk (never `next()` for those rows)
- Confirming which allocate paths must be disabled at cutover

**Not this repo, not this ticket:** disabling Admin `GenrateInvoice`, `RdServiceInvoice`, Completed, bulk, `ProcessRdData`, or Old Admin POS numbering. Sibling Admin-P-02-09-01 already gates **new** `website = rdservice.in` in Admin **source**; deploy status **UNKNOWN**. Old Admin POS still allocates in Admin tests.

Desk must not call Admin allocate URLs. Current Desk source does not.

After cutover, any Admin allocate path that can still mint a **new** number for a channel Desk owns is a dual-issuer incident.

---

## 12. Historical-data preservation

Do not migrate, rewrite, renumber, delete, or archive production data in implementation phases until an explicit import prompt.

| Asset | Rule |
|-------|------|
| Already-issued Admin `invoicecode` / `invoice.invoice` | Register as-is; never allocate a new number for that supply |
| MAX(series) / `rd_no` / `invoice_no` | CA-approved seed of Desk `invoice_sequences.current_value` **after** Admin freeze for that series only |
| Collisions / mismatches | Quarantine; do not silently skip into the live unique index |
| Historical PDFs / IRN | Store read-only; do not regenerate numbers |
| Desk POS `INV-*` | Never import as GST numbers |
| Desk `statutory_invoices` immutability | Keep: posted fields cannot change; rows cannot be deleted; cancelled cannot reopen |

Desk currently has **no** historical statutory import. Paid-uninvoiced production counts: **UNKNOWN** (not queried).

---

## 13. Security considerations

| Surface | Rule |
|---------|------|
| Physical POS | Existing session + `PosAccess` + branch scope. Statutory PDF download uses the same gate. |
| Website ingest | HMAC-SHA256 `{timestamp}{rawBody}`; per-channel secrets; empty secret → 401; replay window; payload channel must match header. Do not add `desk_pos` to this API. |
| Secrets | Never log. Do not reuse Cashfree / BonVoice / `DESK_ORDER_API_TOKEN`. |
| Accountant vs POS | Hardware POS remains 403 on the statutory register (existing test). |
| Rate limit on ingest | Missing today; add before production ingest exposure. |
| Worker | Runs in Desk; no extra public surface. Idempotency prevents double mint on retry. |

---

## 14. Implementation phases

Automatic statutory issuance remains **OFF** in every phase until phase **P** (production gate).

| Phase | Scope | Done when | Must not |
|-------|-------|-----------|----------|
| **P0 — this ADR** | Record owner decisions; validate against source | This document | Code, mint, deploy |
| **P1 — POS pending-invoice work** | After sale persist, write durable pending work in the **same** sale txn. Worker registered. While auto-issue OFF: **no mint**, sale still SUCCESS, no sequence increment. Tests: complete sale; crash/retry of worker; duplicate complete; flag-on-checkout still does not mint inside `completeSale` | **Implemented in RadiumDesk-P-02-09-03** (isolated sqlite). Auto-issue remains OFF. | Enable mint; HTTP POS ingest; GL on invoice; Admin changes |
| **P2 — POS GST payload completeness** | Collect only fields CA already requires or that ingest already treats as eligibility (seller GSTIN completeness, optional buyer GSTIN, place of supply, HSN on SKU). Do not invent split tax | Counter + mint projection tests | Invent CGST/SGST/IGST rules |
| **P3 — Spoke pending-mint worker** | After ingest, same engine, same fail-closed worker pattern. Ingest stays 201/200 with `invoice: null` until gate | Ingest tests still prove zero invoices | Flip `auto_issue_invoice` to true (that flag currently **rejects** ingest) |
| **P4 — Document job** | PDF after issued row; retry PDF; POS session download | PDF failure does not change `invoice_number` | Allocate in the PDF job |
| **P5 — Mint eligibility + GST split** | Only after CA place-of-supply / split rule. Align mint eligibility with ingest (paid, seller GSTIN, POS, HSN) if CA confirms | Tests with **fixtures**, not invented law | Guess intra vs inter state |
| **P6 — Credit notes** | Separate document type/series; cancel stays status+kept number until CN exists | CN does not reuse tax-invoice `next()` | Rewrite historical tax invoices |
| **P7 — Historical register** | Read-only import design then import; freeze Admin first | Original numbers unchanged | Renumber |
| **P — Production-readiness gate** | Changelog, CA series env, Admin allocate off for owned channels, worker mint enabled by a **post-commit** flag, MySQL concurrency green | Explicit owner/CA sign-off | Silent enable; using `auto_issue_on_pos_complete` as currently coded |

**Exact next implementation phase: P2** (POS GST payload completeness). P1 is implemented in RadiumDesk-P-02-09-03.

---

## 15. Explicit non-goals

- POS HTTP ingest / `desk_pos` HMAC channel
- A second statutory invoice engine or Finance-service issuer in this programme
- Local statutory numbering on spokes or on POS
- Minting inside `completeSale` or blocking payment on numbering
- Enabling automatic issuance in P1–P7
- Inventing legal series, FY, GSTIN scope, TCS, shipping GST, IRN policy
- Credit-note/IRN/GSP production integration in P1
- Invoice GL posting (`post_finance_journals` stays false until recognition policy)
- Migrating, rewriting, or deleting historical invoices
- Modifying Old Admin, rdservice.in, radiumbox.com, rdservice.net, radiumsign.com, Stocky, production DB, DNS, or credentials
- Deploy, tag, `deskd`, commit, or push (this prompt)

---

## 16. Remaining UNKNOWN items

Code cannot safely decide these. They do **not** block P1. They **do** block the production gate.

1. Legal invoice series / prefix / `NUMBER_FORMAT` (do not copy Admin `INV67`/`IND67`/`INS` or POS `INV-*`)
2. Financial-year reset vs continuous numbering; FY token string
3. Per-GSTIN series vs one pan-India series
4. Whether B2C walk-in POS requires buyer GSTIN, billing address, or place of supply on every sale
5. CGST/SGST/IGST split and intra- vs inter-state rule
6. Rounding / header-discount presentation on the GST invoice
7. Invoice-on-payment vs any other eligibility timing for **spokes** (POS is paid at complete — DECIDED for physical POS)
8. Revenue recognition vs existing `pos_sale` Cr 4000 (invoice GL remains forbidden)
9. Credit-note series; whether debit notes are used
10. GSP / IRN provider and B2B vs B2C threshold
11. Seller legal name, registered address, signatory for PDF / NIC
12. Correct Bihar (and other) GSTIN values
13. Historical paid-without-invoice treatment
14. Admin production deploy status of the rdservice.in allocate gate
15. Whether Old Admin POS is still live in shops (dual-issuer risk until proven off)
16. Spoke-side outbox existence in website repos
17. Timezone for tax period (app timezone vs IST)
18. MySQL two-process mint on hosts without the disposable harness

---

## Mapping: owner rules → source

| Rule | Current source | Implementation implication |
|------|----------------|------------------------------|
| A Internal `INV-*` ≠ GST | Already separate | Keep |
| B Only Desk allocates new GST numbers | Mint service exists; Admin still capable in sibling source | Desk never calls Admin; Admin cutover is a later Admin prompt |
| C POS key `statutory:desk_pos:inventory_sale:{id}` | Already the mint key | Pending work must use the same key |
| D Sale txn independent of mint | Complete does not mint; **but** auto-issue flag currently fails the sale | P1 enqueue after persist; never mint in `completeSale` |
| E Failed issuance → durable retryable sale | `outbox_events` row `statutory.pos_sale.pending_issue` written in the sale txn (P-02-09-03). Worker no-ops with `blocked_reason=auto_issue_off` | Keep auto-issue OFF |
| F Numbering concurrency/uniqueness/txn/idempotent | Allocator exists | Do not rewrite; add worker caller |
| G PDF after number; retry PDF not number | No PDF yet | P4 |
| H Old Admin not future issuer | Desk does not call Admin | Do not add Admin allocate client |
| I Spokes keep local paid-order resilience | Ingest exists; spoke outbox is other repos | Do not require spoke checkout to wait on mint |
| J Auto-issue OFF until production gate | Hard-false flags | P1 worker must no-op; do not flip current flags |

---

## Safety record (P-02-09-02)

| Action | Done? |
|--------|-------|
| Modify application code | No |
| Mint / increment sequences | No |
| Production DB | No |
| Enable auto-issue | No |
| Commit / push / deploy | No |
| Modify Admin or other projects | No |

---

## P1 implementation (RadiumDesk-P-02-09-03)

VERIFIED in this worktree:

- `PosSaleService::completeSale` writes `outbox_events` in the **same** DB transaction as the sale, after the internal receipt and `pos_sale` journal, before commit.
- Idempotency/source key is `statutory:desk_pos:inventory_sale:{id}` (`outbox_events.idempotency_key` unique). Duplicate complete uses `firstOrCreate`.
- Worker: `outbox:process` → `PosStatutoryPendingIssueProcessor`. While automatic issuance is OFF it **does not** call mint/allocate. It records `payload.blocked_reason = auto_issue_off` and the outbox row is marked completed so cron does not spin. Re-queueing the same row still does not mint.
- `auto_issue_on_pos_complete` and `channel_ingest.auto_issue_invoice` remain **false**. Enabling the POS flag still rejects checkout.
- Finance failure before the outbox write rolls back the sale and the pending row together.

Do not mint in P2. Next: GST field capture only if CA requires it.
