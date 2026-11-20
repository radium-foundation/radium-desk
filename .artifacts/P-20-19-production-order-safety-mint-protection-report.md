# P-20-19 — Production order safety and automatic mint protection

| Field | Value |
| --- | --- |
| Task | **P-20-19** |
| Cursor ledger | `bd0ac5bd-88f0-48e0-aacd-66eca9be9aef` |
| Desk ledger row | **RadiumDesk-P-20-19** |
| Risk | **HIGH** |
| Start (IST) | **2026-11-20 10:22:56** |
| End (IST) | **2026-11-20 10:31:00** (approx.) |
| Duration | **~8 min** (read-only prod SSH + code trace) |
| Production mutations | **NONE** (no Issue Invoice, mint, flags, workers, deploy) |

## Executive verdict

| Question | Answer | Class |
| --- | --- | --- |
| Should Owner click **Issue Invoice** on **RBP2295** now? | **NO** — mint should **fail closed** on Stage A GST review hold; order is **not** suitable as a controlled GST journey fixture | **VERIFIED** (gate + eligibility) |
| Would click invoke statutory **mint** if it succeeded? | **YES** — `StatutoryInvoiceService::mint()` allocates sequence and creates **`status=issued`** invoice | **VERIFIED** (code) |
| Does an invoice already exist for **RBP2295**? | **NO** (`existing_si=0`, `statutory_invoice_id` null) | **VERIFIED** (prod) |
| Is repeat Issue idempotent? | **YES** after success (`findBySource` returns existing); **N/A** today (no invoice) | **VERIFIED** (code) |
| Production known-good? | **NOT claimed** | — |

---

## Step 1 — Production identity (VERIFIED)

| Item | Value |
| --- | --- |
| Host / app root | **`deskvps`** → `/var/www/radium-desk` |
| Release | **`storage/app/private/release.json`**: **v4.1.102** / **`339e3dc99`**, deployed **2026-11-20 08:41:35 IST** |
| Database | **`radium_desk`** |
| Recovery backup (P-20-16) | **`20261120T041822Z`** @ **`339e3dc99`** (drill proven; **not re-run** this task) |
| `max(statutory_invoices.id)` at close | **23280** (**+9** since P-20-17 baseline 23270; **not** attributed to RBP2295 — **UNKNOWN** detail) |

### Runtime flags (VERIFIED, preserved)

| Control | Runtime |
| --- | --- |
| `STATUTORY_BUYER_GSTIN_VERIFICATION_ENABLED` | **`true`** |
| `STATUTORY_BUYER_GSTIN_B2C_FALLBACK_ENABLED` | **`false`** |
| Stage B (fallback policy) | **OFF** |
| `channel_ingest.auto_issue_invoice` | **`false`** |
| `service_statutory_invoice.auto_issuance.enforce` | **`true`** |
| `service_statutory_invoice.auto_issuance.channel_defaults.radiumbox_com` | **`allow_automatic`** |
| `service_statutory_invoice.reconciliation.enabled` | **`true`** |
| RadiumBox receipt publish | **`enabled=true`**, allowlist **8** IDs **`[16543, 16947, 17027–17032]`** (cached config) |

### Worker status

| Check | Result |
| --- | --- |
| `supervisorctl status radium-desk-queue-worker` | **BLOCKED** — SSH user **Permission denied** on supervisor socket |
| Queue worker since P-20-17 | **INFERRED** running (restart succeeded **10:02 IST**; no contradictory evidence) |

---

## Step 2 — Order **RBP2295** identity (VERIFIED)

| Field | Value |
| --- | --- |
| Commerce order PK | **23661** (`source_id` **RBP2295**, channel **`radiumbox_com`**) |
| Status | **`validated`**, **paid** |
| `statutory_invoice_id` | **null** |
| Buyer GSTIN | **`07AAICB5215F2ZX`** (B2B shape) |
| Billing state (order) | **Rajasthan** |
| Hardware fulfilment | **id 1534**, state **`serials_allocated`** since **2026-11-19 04:23:39 UTC** |
| Desk support order | **id 74517**, `order_id` **RBP2295**, **`active`** |
| `BusinessOrderId::isRadiumBoxService('RBP2295')` | **`false`** (product/hardware, **not** **RB*** service auto path) |

### Buyer GST verification row (VERIFIED; created in P-20-17 window)

| Field | Value |
| --- | --- |
| `verification_status` | **`review_required`** |
| `review_reason` | **`inactive_registration`** |
| `registration_status` (provider) | **`Suspended`** |
| `legal_name` | B-IOT SOLUTIONS PRIVATE LIMITED |
| Submitted addresses | **Jodhpur, Rajasthan** |
| Provider registration address | **Delhi** (GSTIN state **07** vs billing **08** Rajasthan — **state mismatch**) |
| `bill_to_address_structured` | **null** (not verified bill-to) |

### Statutory eligibility (VERIFIED, prod `evaluateOrder`)

```
eligible: false
errors:
  - "A line is missing description, HSN/SAC, quantity, price, or statutory amounts."  # commerce line gst_percentage null
  - "Buyer GSTIN requires GST review."
CommerceBuyerGstMintGate::blockingReasonsForOrder: ["Buyer GSTIN requires GST review."]
HardwareCommerceStatutoryInvoiceGuard::blockingErrors: []
```

**INFERRED:** Hardware `HardwareInclusiveGstReconciler` can derive rate from taxable/tax/line_total for mint request lines; the **`gst_percentage` null** on `commerce_order_items` affects **Finance pending eligibility UI**, not necessarily hardware `mintRequest()` — **primary production blocker remains GST review gate**.

---

## Step 3 — Issuance path trace (VERIFIED code + prod context)

### A. Manual — Finance Hub “Issue Invoice”

`StatutoryInvoiceIssueController::issue` → `StatutoryInvoiceService::issueFromCommerceOrder`:

- **Existing invoice:** none → continue.
- **`requiresHardwareSerialPath`:** **true** → **skips** `StatutoryMintEligibility::assertOrderCanMint` (hardware fast path).
- **`HardwareCommerceStatutoryInvoiceGuard::issue`** → **`HardwareFulfilmentInvoiceService::issueInvoice`** → **`StatutoryInvoiceService::mint(mintRequest(...))`**.
- **`mint()`** always runs **`assertB2bInputReadinessForMint`** → **`CommerceBuyerGstMintGate::blockingReasonsForMintRequest`** → throws **`ValidationException`** with **`einvoice_readiness`** message including **`Buyer GSTIN requires GST review.`** when Stage A hold applies.

**VERIFIED:** Manual click is a **real mint attempt**; with current data it should **fail** before sequence allocation (no new `statutory_invoices` row).

### B. Manual — Hardware fulfilment UI

`HardwareFulfilmentSerialController::storeInvoice` → same **`issueInvoice`** / **`mint()`** stack → **same GST gate**.

### C. Automatic — post-serial hardware

`HardwareSerialAllocationService` → **`HardwareStatutoryInvoiceIssuer::issueAfterSerialsAllocated`** → **`issueInvoice`** (failures caught, **`Log::warning('hardware_statutory_invoice.serial_issue_failed', …)`**).

| Evidence | Result |
| --- | --- |
| Prod `laravel.log` grep `hardware_statutory` / `serial_issue_failed` / `RBP2295` | **No rows found** (**UNKNOWN** whether failure was swallowed without log retention or never attempted in this log file) |
| Fulfilment still **`serials_allocated`** (not **`invoice_issued`**) | **VERIFIED** — auto path **did not** complete invoice |

### D. Service auto-issuance (RB* / workflow) — **not applicable** to RBP2295

| Path | Applies to RBP2295? |
| --- | --- |
| `ServiceStatutoryInvoiceIssuanceCoordinator` / `issueFromSupportOrder` | **NO** — product ref; coordinator used for **service** workflow triggers (cf. P-20-18 **RB1921**) |
| `ServiceStatutoryInvoiceReconciliationService` | **NO** — skips rows where `HardwareCommerceStatutoryInvoiceGuard::requiresHardwareSerialPath` (**VERIFIED** code) |
| `statutory.invoice.service_mint` outbox for **23661** / **RBP2295** | **0** events (**VERIFIED**) |

### E. Channel ingest auto issue

**OFF** — ingest does not mint.

---

## Step 4 — Mint / idempotency (VERIFIED)

| Mechanism | Behaviour |
| --- | --- |
| Pre-check | `findBySource(radiumbox_com, CommerceOrder, RBP2295)` — **null** today |
| On success | Same key returns existing invoice + relink (idempotent) |
| On validation failure | **No** invoice row; safe to retry after data/policy fix |
| IRN / receipt | Mint success would enqueue e-invoice eligibility pipeline and RadiumBox receipt outbox (publisher on; **RBP2295** not on allowlist → publication **awaiting_activation** pattern — **INFERRED** from P-20-15 behaviour) |

**Task constraint:** **No mint invoked** in this investigation.

---

## Step 5 — GST / statutory readiness (VERIFIED / INFERRED)

| Topic | Finding | Class |
| --- | --- | --- |
| B2B with GSTIN on order | Yes | **VERIFIED** |
| Provider verification | **Suspended** / **inactive_registration** | **VERIFIED** |
| B2C fallback | **OFF** — no auto downgrade | **VERIFIED** |
| Bill-to for mint | **Unresolved** (`bill_to_address_structured` null) | **VERIFIED** |
| Place of supply / seller | Not evaluated to IRN in this task (**no mint**) | **NOT RUN** |
| Statutory mint eligibility (standard evaluator) | **false** | **VERIFIED** |

---

## Step 6 — Suitability for controlled production GST journey

| Criterion | Assessment |
| --- | --- |
| Live paid customer order | **YES** — unsuitable as test fixture |
| Existing P-20-17 WhiteBooks evidence on same order | **YES** — couples journey to production audit row **id 1** |
| GST review hold | **YES** — blocks B2B mint under Stage A |
| Fulfilment / shipment dependency | Awaiting invoice for downstream **`invoice_issued`** |

**Recommendation:** **Do not** use **RBP2295** / **23661** for GST Stage A/B mint journeys. Use **synthetic / owner-designated** orders only.

---

## Step 7 — `radiumbox_com` automatic issuance policy (VERIFIED)

| Layer | Hardware **RBP*** | Service **RB*** |
| --- | --- | --- |
| `ServiceStatutoryInvoiceAutoIssuancePolicy` | N/A (manual/hardware path) | **`allow_automatic`** when enforce **true** |
| Reconciliation scheduler | **Skipped** (hardware serial path) | **Eligible** service rows only |
| P-20-18 incident pattern | Hardware manual/serial path | **RB1921** auto mint **23271** |

**Risk:** With **`allow_automatic`** + **`enforce=true`**, unrelated **RB*** service completions can still mint during GST test windows (demonstrated **2026-11-20 ~10:02 IST**).

---

## Step 8 — Mint moratorium & journey isolation (recommendations only — **NOT applied**)

| Control | Purpose | Operational dependency | Rollback |
| --- | --- | --- | --- |
| **`SERVICE_STATUTORY_INVOICE_AUTO_ISSUANCE_ENFORCE=false`** | Pauses **automatic** service mint (coordinator, outbox, reconciliation attempts) | Env + `config:cache` + queue worker reload | Set **true** + cache |
| Change **`channel_defaults.radiumbox_com`** to **`require_explicit_approval`** (code/config) | Narrow auto mint to approved rows | Deploy or config change | Revert default |
| **Operational moratorium** | No manual **Issue Invoice** on prod customer orders during Stage A/B | Process only | N/A |
| Hardware **post-serial auto mint** | Still calls `issueInvoice` on serial commit — **no prod kill-switch** found | Would need code flag or hold fulfilment | **UNKNOWN** safe prod toggle |
| Journey isolation | UAT / synthetic orders; never reuse **23661** | P-19-67 UAT still **BLOCKED** for GST | — |
| Receipt allowlist | Already limits Box publish; **does not** block mint | Independent | — |

**Rollback limit:** Disabling auto-issuance **does not** revoke invoices already minted; it only stops **new** automatic attempts.

---

## Step 9 — Acceptance criteria

| Criterion | Result |
| --- | --- |
| Screenshot order invoice readiness established | **PASS** |
| No invoice minted/altered | **PASS** |
| Auto-issuance paths documented | **PASS** |
| Isolation approach proposed | **PASS** |
| Production unchanged | **PASS** |
| Next Owner decision identified | **PASS** |

---

## Step 10 — PASSED / FAILED / BLOCKED / NOT RUN

| Activity | Result |
| --- | --- |
| Prod verify (release, DB, flags, order) | **PASSED** |
| Issuance path code trace | **PASSED** |
| Live mint / Issue Invoice | **NOT RUN** (prohibited) |
| Supervisor worker verify | **BLOCKED** |
| Log proof of serial auto-failure | **BLOCKED** / **UNKNOWN** |
| IRN / WhiteBooks on mint path | **NOT RUN** |
| Fresh backup drill | **NOT RUN** |

---

## Owner recommendation — Issue Invoice on **RBP2295**?

**Do not issue** until:

1. **GST review** on **`07AAICB5215F2ZX`** is resolved (suspended registration, state/bill-to alignment, verified bill-to snapshot) under Finance/CA process — Stage A **blocks** mint with **`Buyer GSTIN requires GST review.`**
2. Order is **explicitly approved** for fulfilment invoice (live customer — not a test fixture).
3. Optional: separate **moratorium** on service **auto-issuance** during further GST observability (see §8).

Manual click **should not** mint today; it **will** still **attempt** mint and may surface validation errors in UI — treat as **high-risk** until holds cleared.

---

## Exact next approval required

| # | Decision |
| --- | --- |
| 1 | **Owner:** Confirm **no Issue Invoice** on **RBP2295** until GST review complete (**recommended: HOLD**). |
| 2 | **Owner:** Authorize **GST adjudication** (reactivation proof, bill-to, Rajasthan/Delhi POS policy) for **23661** — separate from mint. |
| 3 | **Owner (optional):** Authorize **service auto-issuance moratorium** (`ENFORCE=false` or policy change) during controlled GST journeys — **config change**, not done in P-20-19. |
| 4 | **Owner:** Choose **non-production** fixture for any mint-dependent GST gate (not **RBP2295**). |

---

## References

- P-20-17 journey **#8** — same order **23661**, WhiteBooks → **review_required**.
- P-20-18 — unrelated **RB1921** service auto mint **23271**.
- Code: `StatutoryInvoiceService::issueFromCommerceOrder`, `CommerceBuyerGstMintGate`, `HardwareStatutoryInvoiceIssuer`, `ServiceStatutoryInvoiceReconciliationService`.