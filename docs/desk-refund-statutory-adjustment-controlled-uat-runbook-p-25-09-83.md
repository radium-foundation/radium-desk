# Controlled production UAT runbook — refund statutory adjustment

**Prompt ID:** RadiumDesk-P-25-09-83  
**Date:** 2026-09-27  
**Repository:** `radium-foundation/radium-desk`  
**Deployed release:** v4.0.151 / build `7d565746`  
**Feature flag:** `REFUNDS_STATUTORY_ADJUSTMENT_ENABLED` — default **OFF**  
**Status:** PLAN ONLY — **do not execute** from this document until Owner approval checklist (companion doc) is complete.

---

## Evidence classification (read before UAT)

| Class | Meaning | Applies to refund-adjustment UAT |
| --- | --- | --- |
| **Code-derived** | Behavior inferred from deployed PHP/services | Eligibility, outbox retry, policy routing, WhiteBooks adapter shape |
| **Automated-test evidence** | PHPUnit with `Http::fake` / in-memory DB | CANCEL/CRN payload mapping, idempotency, retry → `failed_manual` after 5 attempts |
| **Historical production evidence** | Prior authorized production actions on real/disposable records | POS cancellation UAT (P-23-09-46, P-25-09-27); CN IRN on INV-0767138 (P-25-09-57). **Not** refund-triggered adjustment. |
| **Live provider proof** | Authorized call to WhiteBooks/NIC with captured request/response | **CANCEL on refund path: NOT VERIFIED.** **CRN GENERATE on refund path: NOT VERIFIED.** |

No verified WhiteBooks/NIC sandbox exists (P-25-09-81, P-25-09-82). UAT uses production API at `https://api.whitebooks.in` only.

---

## 1. Preconditions

Complete **before** any UAT window opens.

| # | Requirement | Owner / responsible | Evidence to retain |
| --- | --- | --- | --- |
| 1.1 | **Owner written approval** for controlled production UAT | Owner | Signed UAT packet (date, scope, scenarios) |
| 1.2 | **CA/GST practitioner approval** for B2B IRN cancel and CN IRN scenarios | CA/GST practitioner | Written acknowledgment of GST/statutory impact |
| 1.3 | **Provider contract proof gate** | Owner + Finance | Live CANCEL + CRN proof from UAT scenarios **or** separate authorized provider verification — see Owner checklist |
| 1.4 | **Production backup/snapshot** | Operator | Path + timestamp (pattern: `storage/app/backups/p-25-09-83-<UTC>/` or project backup run per `docs/backup-runbook.md`) |
| 1.5 | **Protected-invoice denylist** | Owner | Explicit list of invoice IDs / numbers that must **not** be touched; operator confirms zero overlap with disposable records |
| 1.6 | **Test window** | Owner | Documented **start** and **end** (Asia/Kolkata); flag ON only inside window |
| 1.7 | **UAT operator** | Owner | Named operator with Finance refund + statutory permissions |
| 1.8 | **Observer/reviewer** | Owner | Second person reviews SQL/log evidence before flag OFF |
| 1.9 | **Rollback acknowledgement** | Owner + operator | Signed acknowledgment of §13 rollback limitations |

**Hard exclusions**

- **POS statutory invoices** are out of scope for automatic v1 adjustment (`OrderStatutoryInvoiceResolver` → `pos_statutory_boundary`). Do not use POS disposable UAT records (e.g. P-25-09-27 POS-6757) to validate this feature.
- **No real customer** commerce/service orders unless Owner explicitly adds them to the disposable UAT plan (default: **new disposable records only**).
- **No historical backfill** of completed refunds.

---

## 2. Disposable test data requirements

Create **three** separate disposable records during UAT (not before this prompt). Do not reference or mutate existing production customer invoices in planning artifacts.

### 2.1 Scenario A — B2B &lt;24h IRN → CANCEL

| Attribute | Requirement |
| --- | --- |
| Order path | Commerce or **service** support order with linked Desk `orders` row — **not** POS (`inventory_sale_id` must be null on statutory invoice) |
| Buyer | Valid B2B GSTIN on statutory invoice (`buyer_gstin` normalized and valid) |
| Invoice | Issued **tax invoice** (`document_type = tax_invoice`, `status = issued`) |
| IRN | Submitted IRN on `e_invoice_records` (`status` submitted/issued per deployed enum); `ack_date` **&lt; 24 hours** before refund completion at adjustment time |
| CN | No existing credit note (`original_statutory_invoice_id` child) |
| Refund | Terminal-success **full** refund (cumulative paid amount fully refunded per `RefundStatutoryAdjustmentEligibility`) |
| Expected policy | `B2bWithinWindowCancelInvoice` — WhiteBooks **CANCEL** then local invoice cancel |

### 2.2 Scenario B — B2B ≥24h IRN → CRN

| Attribute | Requirement |
| --- | --- |
| Order path | Same as §2.1 — commerce/service, not POS |
| Buyer | Valid B2B GSTIN |
| Invoice | Issued tax invoice with submitted IRN |
| IRN age | `ack_date` **≥ 24 hours** before refund completion (separate invoice from Scenario A) |
| CN | No pre-existing CN on original |
| Refund | Full refund as §2.1 |
| Expected policy | `B2bBeyondWindowCreditNote` — original invoice **stays issued**; GST credit note minted; CN IRN queued via existing e-invoice outbox |

### 2.3 Scenario C — B2C control

| Attribute | Requirement |
| --- | --- |
| Buyer | No valid B2B GSTIN (B2C) |
| Invoice | Issued tax invoice (IRN may be skipped or not required per eligibility) |
| Refund | Full refund |
| Expected adjustment | `not_applicable` **or** local cancel path only — **no** WhiteBooks CANCEL/CRN for IRN workflow |

### 2.4 POS explicit exclusion

Any statutory invoice with `inventory_sale_id` set or `source_type` inventory sale → resolver returns `pos_statutory_boundary`. POS UAT does **not** validate refund → statutory adjustment.

---

## 3. Pre-UAT evidence capture

Capture for **each** scenario **before** enabling the flag and **before** completing the refund (fields left blank until execution).

### 3.1 Record template

| Field | Source |
| --- | --- |
| Scenario ID | A / B / C |
| Statutory invoice ID | `statutory_invoices.id` |
| Invoice number | `statutory_invoices.invoice_number` |
| Order ID | `orders.order_id` (support/commerce link) |
| Refund request ID | `refund_requests.id` (after created) |
| Refund reference | `refund_requests.reference_no` |
| Invoice status | `statutory_invoices.status` |
| Document type | `statutory_invoices.document_type` |
| Buyer GSTIN | `statutory_invoices.buyer_gstin` |
| B2B classification | Valid GSTIN present → B2B; else B2C |
| Invoice amount | `statutory_invoices.invoice_value` (or authoritative total field) |
| Refund amount | Refund display amount |
| IRN | `e_invoice_records.irn` |
| IRN record status | `e_invoice_records.status` |
| IRN submission time | `e_invoice_records.ack_date` |
| IRN age at refund (hours) | Computed vs `STATUTORY_EINVOICE_IRN_CANCELLATION_WINDOW_HOURS` (default 24) |
| Existing CN | `SELECT` child where `original_statutory_invoice_id = :invoice_id` |
| `refund_statutory_adjustments` | Count for refund_id (expect 0 pre-refund) |
| Outbox | `outbox_events` for `event_type = refund.statutory_adjustment` (expect 0) |
| CA Monthly baseline | Export ID, period, row count, cancelled count, CN count — store artifact hash/path |

### 3.2 Baseline SQL (read-only)

```sql
-- Adjustment row (expect none pre-UAT)
SELECT * FROM refund_statutory_adjustments WHERE refund_request_id = :refund_id;

-- Invoice + IRN
SELECT si.id, si.invoice_number, si.status, si.document_type, si.buyer_gstin,
       si.invoice_value, si.inventory_sale_id, e.irn, e.status AS irn_status, e.ack_date
FROM statutory_invoices si
LEFT JOIN e_invoice_records e ON e.invoice_id = si.id
WHERE si.id = :invoice_id;

-- Existing CN
SELECT id, invoice_number, status FROM statutory_invoices
WHERE original_statutory_invoice_id = :invoice_id AND document_type = 'credit_note';

-- Outbox (refund statutory)
SELECT id, status, attempts, available_at, last_error, processed_at
FROM outbox_events
WHERE event_type = 'refund.statutory_adjustment'
  AND aggregate_id = :refund_request_id;
```

---

## 4. Enablement procedure (UAT window only)

**Do not run** until §1 preconditions are satisfied.

### 4.1 Enable (start of UAT window)

1. Confirm production release: `storage/app/private/release.json` → version **4.0.151**, build **7d565746**.
2. Backup production `.env` → `.env.bak-p-25-09-83-<UTC>`.
3. Set `REFUNDS_STATUTORY_ADJUSTMENT_ENABLED=true` in production `.env`.
4. Refresh config cache (production uses cached config):
   ```bash
   cd /var/www/radium-desk
   php artisan config:clear
   php artisan config:cache
   ```
5. **Runtime verification** (read-only):
   ```bash
   php artisan tinker --execute="echo json_encode(['flag'=>config('refunds.statutory_adjustment.enabled'),'provider'=>config('statutory_invoices.einvoice.provider'),'base'=>config('statutory_invoices.einvoice.gsp_base_url')]);"
   ```
   Expect: `flag` = true, `provider` = whitebooks, `base` = `https://api.whitebooks.in`.
6. Record **UAT window start** (timestamp, operator).
7. Confirm scheduler/outbox path active: `schedule:light-tick` invokes `outbox:process` (see `ScheduleLightTickCommand`).

### 4.2 Disable (end of UAT window — mandatory)

1. Set `REFUNDS_STATUTORY_ADJUSTMENT_ENABLED=false` or remove key (default false).
2. `php artisan config:clear && php artisan config:cache`
3. Verify `config('refunds.statutory_adjustment.enabled')` === false.
4. Record **UAT window end** (timestamp, operator).
5. Observer confirms no new `refund.statutory_adjustment` outbox events after window end (except in-flight processing).

---

## 5. B2B &lt;24h UAT (Scenario A)

### 5.1 Execution sequence (authorized only)

1. Mint disposable commerce/service order + issued B2B statutory tax invoice.
2. Submit IRN to WhiteBooks (existing issuance path — **separate** authorized step).
3. Complete §3 evidence capture.
4. Enable flag (§4.1).
5. Complete **full** refund to terminal success (`RefundCompleted` event fires).
6. Wait for outbox processing (monitor §9).
7. Disable flag (§4.2) after scenario evidence captured.

### 5.2 Expected adjustment state transitions

| Stage | `refund_statutory_adjustments.status` | Notes |
| --- | --- | --- |
| After refund + enqueue | `pending` | Row created; outbox `refund.statutory_adjustment` pending |
| After successful orchestrator | `succeeded` | `processed_at` set; `orchestrator_result` JSON populated |
| On transient provider/orchestrator error | `failed_retryable` | Outbox retries (max 5) |
| After max retries or permanent failure | `failed_manual` | Requires §8 manual escalation |

### 5.3 Expected WhiteBooks CANCEL (code-derived — **not live-verified**)

- Endpoint: `POST /einvoice/type/CANCEL/version/V1_03?email={issuer_email}`
- Body: `{ Irn, CnlRsn: "3", CnlRem: "Automatic statutory adjustment after completed refund {ref}." }`
- Success signals (mapper): `CancelDate` / `CancelDt`, or status CNL/CAN/Cancelled, or `status_cd=1` with `Irn`
- **Live proof:** capture HTTP request/response in provider logs or `e_invoice_records.response_payload` only after authorized execution

### 5.4 Expected invoice/IRN state (code-derived)

- `e_invoice_records`: IRN marked cancelled per `StatutoryInvoiceIrnCancellationService` outcome
- `statutory_invoices.status`: `cancelled`
- `statutory_invoice_cancellations`: one row; `idempotency_key` = `refund-statutory-adjustment:invoice:{invoice_id}`
- Audit: `audit_logs.event` = `statutory_invoice.cancelled` on invoice

### 5.5 Expected outbox/audit

| Artifact | Expectation |
| --- | --- |
| `outbox_events` | `event_type = refund.statutory_adjustment`, `idempotency_key = refund-statutory-adjustment:refund:{refund_id}`, terminal `status = completed` |
| `audit_logs` | Cancellation event on statutory invoice |
| Refund UI | Refund show → **Statutory Adjustment** card → status `succeeded` |

### 5.6 Expected CA Monthly effect (code-derived)

- Original invoice row: **Cancelled** status in export
- No new CN row for this scenario
- Re-export period covering test date; compare to §3.1 baseline

### 5.7 Reconciliation evidence (acceptance)

- [ ] Adjustment `succeeded`; skip_reason null
- [ ] Invoice `cancelled`; IRN cancelled in NIC-aligned local state
- [ ] No duplicate `refund_statutory_adjustments` for same `refund_request_id`
- [ ] No CN child on original invoice
- [ ] Outbox completed; `attempts` ≤ 5
- [ ] CA Monthly cancelled count +1 vs baseline (disposable row only)
- [ ] Protected denylist invoices unchanged

---

## 6. B2B ≥24h UAT (Scenario B)

### 6.1 Execution sequence

Same as §5.1 except IRN age ≥ 24h and **separate** disposable invoice/order.

### 6.2 Expected CN creation (code-derived)

- Original invoice **remains** `issued`
- New `statutory_invoices` row: `document_type = credit_note`, `original_statutory_invoice_id` → original
- `statutory_invoice_cancellations.credit_note_action` populated in orchestrator result
- Orchestrator event may be `statutory_invoice.cancellation_adjusted` (beyond-window path)

### 6.3 Expected CN IRN generation (code-derived — **not live-verified on refund path**)

- CN enters existing e-invoice submission queue (`EInvoiceOutboxWriter` / processor)
- WhiteBooks `POST …/GENERATE/V1_03` with `DocDtls.Typ = CRN` and `RefDtls.PrecDocDtls`
- **Historical production evidence:** P-25-09-57 issued CN IRN for INV-0767138 correction — **not** refund-triggered
- **Live proof required:** captured CN IRN ack on disposable Scenario B record

### 6.4 Expected outbox/audit

- `refund_statutory_adjustments.status` = `succeeded`
- `orchestrator_result.credit_note_action` non-empty
- Possible additional outbox events for CN IRN (e-invoice event types — verify by `aggregate_id` / invoice id)

### 6.5 Expected CA Monthly paired netting (code-derived)

- Original invoice: remains issued (not cancelled)
- CN row: negative/tax-offset values per CA Monthly credit-note rules (v4.0.145+)
- Paired original + CN net to zero for the refunded commercial amount (regression-tested in PHPUnit; **re-verify on disposable export**)

### 6.6 Reconciliation evidence (acceptance)

- [ ] Adjustment `succeeded`
- [ ] Original invoice still `issued`; original IRN unchanged on NIC
- [ ] Exactly one CN linked via `original_statutory_invoice_id`
- [ ] CN IRN submitted or documented failure state — no silent success
- [ ] CA Monthly export shows paired rows; netting verified
- [ ] Protected denylist unchanged

---

## 7. B2C control (Scenario C)

### 7.1 Expected behavior (code-derived)

| Check | Expectation |
| --- | --- |
| Policy | `B2cCancelInvoice` — local cancellation without IRN workflow |
| WhiteBooks CANCEL | **Must not** be invoked for IRN cancel (no submitted IRN path) |
| WhiteBooks CRN GENERATE | **Must not** be invoked |
| Adjustment status | `succeeded` (local cancel) **or** `not_applicable` if resolver/eligibility skips |
| Invoice | Cancelled locally if orchestrator runs B2C path |

### 7.2 Verification

- Confirm no `CANCEL` or CRN `GENERATE` in application logs / `e_invoice_records` provider payloads for B2C disposable invoice
- Refund UI card reflects terminal status with appropriate skip reason if applicable

---

## 8. Failure handling

Distinguish **safe automatic retry** vs **stop and reconcile**.

### 8.1 Provider HTTP / transport (WhiteBooks gateway — code-derived)

| Condition | Gateway classification | Adjustment status | Safe to retry? | Operator action |
| --- | --- | --- | --- | --- |
| **401 / 403** | Permanent (`cancel_unauthorized`) | → `failed_manual` | **No** | Fix credentials/IP whitelist; do not loop retry |
| **429** | Unknown (`cancel_rate_limited`) | `failed_retryable` then manual | Outbox retries only | Wait/backoff; abort UAT if sustained |
| **5xx** | Unknown (`cancel_provider_5xx`) | `failed_retryable` | Outbox up to 5 attempts | Monitor; abort if persistent |
| **Timeout / transport** | Unknown | `failed_retryable` | Outbox retries | Check network/provider status |
| **400–499** (other) | Permanent validation | `failed_manual` | **No** | Finance + provider reconcile IRN state |
| **Malformed JSON 200** | Unknown | `failed_retryable` | Limited | Treat as ambiguous — §8.2 |
| **`status_cd=0`** | Permanent reject | `failed_manual` | **No** | Provider message review |
| **Ambiguous body** | `ambiguous_cancel_response` | `failed_retryable` / manual | **No** without NIC state check | Get-IRN / provider portal before retry |

CN GENERATE failures follow existing e-invoice processor semantics (separate outbox event).

### 8.2 `failed_retryable`

- Outbox `OutboxProcessorService::MAX_ATTEMPTS` = **5**; backoff **30s, 2m, 10m, 30m**
- Automatic retry via `schedule:light-tick` → `outbox:process`
- **Safe retry when:** transient 5xx/timeout and NIC IRN state unchanged (still active)
- **Not safe when:** ambiguous cancel response, wrong invoice association, or partial cancel suspected

### 8.3 `failed_manual`

- Terminal for adjustment row; outbox `status = failed`
- **No deployed artisan command** for refund-adjustment retry
- Manual recovery options (Owner-authorized only):
  1. Reconcile IRN/NIC state via Get-IRN / provider tools
  2. Finance orchestrator cancel with **different** idempotency key only if policy allows and duplicate risk assessed
  3. Document incident; do **not** enable general production flag until resolved

### 8.4 Duplicate / retry race

| Mechanism | Key |
| --- | --- |
| One adjustment per refund | UNIQUE `refund_request_id` |
| Outbox idempotency | `refund-statutory-adjustment:refund:{refund_id}` |
| Orchestrator idempotency | `refund-statutory-adjustment:invoice:{invoice_id}` |
| Second full refund same invoice | Eligibility skip `invoice_already_adjusted` |

Duplicate outbox processing: orchestrator returns idempotent result; adjustment remains `succeeded`.

### 8.5 Worker / outbox failure

- If `outbox:process` not running: adjustments stay `pending`
- Check: scheduler heartbeat, `storage/logs/outbox-processor.log`, `storage/logs/schedule-light-tick.log`
- Queue worker restart does **not** replace outbox processor — scheduler tick required

---

## 9. Monitoring

### 9.1 SQL checks

```sql
-- Adjustment summary
SELECT status, COUNT(*) FROM refund_statutory_adjustments GROUP BY status;

-- Pending / failed for UAT refunds
SELECT rsa.*, rr.reference_no
FROM refund_statutory_adjustments rsa
JOIN refund_requests rr ON rr.id = rsa.refund_request_id
WHERE rsa.refund_request_id IN (:uat_refund_ids);

-- Outbox backlog (refund statutory)
SELECT id, aggregate_id, status, attempts, available_at, last_error, processed_at
FROM outbox_events
WHERE event_type = 'refund.statutory_adjustment'
ORDER BY id DESC LIMIT 20;

-- Invoice + IRN post-state
SELECT si.id, si.invoice_number, si.status, si.document_type,
       e.irn, e.status AS irn_status, e.ack_date
FROM statutory_invoices si
LEFT JOIN e_invoice_records e ON e.invoice_id = si.id
WHERE si.id IN (:uat_invoice_ids);

-- CN children
SELECT id, invoice_number, status, original_statutory_invoice_id
FROM statutory_invoices
WHERE original_statutory_invoice_id IN (:uat_original_invoice_ids);

-- Cancellation records
SELECT * FROM statutory_invoice_cancellations
WHERE statutory_invoice_id IN (:uat_invoice_ids);

-- Audit (recent statutory cancel)
SELECT id, event, auditable_type, auditable_id, created_at
FROM audit_logs
WHERE event IN ('statutory_invoice.cancelled', 'statutory_invoice.cancellation_adjusted')
  AND created_at >= :uat_window_start
ORDER BY id DESC;
```

### 9.2 Logs

| Log | Path | Purpose |
| --- | --- | --- |
| Outbox processor | `storage/logs/outbox-processor.log` | `refund.statutory_adjustment` processing errors |
| Light tick | `storage/logs/schedule-light-tick.log` | Scheduler invoked `outbox:process` |
| Application | `storage/logs/laravel.log` | Listener enqueue failures `[Refund] Statutory adjustment enqueue listener failed` |

### 9.3 UI

- Refund detail → **Statutory Adjustment** card: status, invoice number, skip_reason, failure_reason, processed_at

### 9.4 Worker / scheduler health

- `supervisorctl status radium-desk-queue-worker` (queue — ancillary)
- Scheduler cron / `operations:scheduler-heartbeat` per `bootstrap/app.php`
- `/up` returns 200

---

## 10. Reconciliation

### 10.1 Before/after matrix

| Domain | Before | After (per scenario) | Acceptance |
| --- | --- | --- | --- |
| Refund | Terminal success | Unchanged | Refund amount/method correct |
| Statutory invoice | Issued | Cancelled (A/C B2C) or issued + CN (B) | Matches policy |
| IRN | Active | Cancelled (A) or unchanged (B) | NIC-aligned local state |
| CN | None | None (A/C) or one CN (B) | No duplicates |
| Audit | — | Cancel/adjust events present | Actor = system automation user |
| Outbox | None/completed | Completed (or documented failure) | No stuck `pending` > 30m post-window |
| CA Monthly | Baseline export | New export same period | Counts/netting per §5.6 / §6.5 |

### 10.2 Global acceptance (all scenarios)

- [ ] Only disposable UAT invoice IDs appear in adjustment rows
- [ ] Protected denylist invoices unchanged (re-run denylist SQL)
- [ ] `refund_statutory_adjustments` count = number of UAT refunds (max 3)
- [ ] Flag OFF verified post-UAT
- [ ] Owner + CA sign reconciliation packet

---

## 11. Abort criteria

**Stop UAT immediately** (disable flag §4.2) if any:

| # | Condition |
| --- | --- |
| 11.1 | Unexpected provider response not matching §5.3 / §6.3 expectations |
| 11.2 | Wrong invoice or order association in adjustment row |
| 11.3 | Ambiguous IRN state (local vs provider mismatch) |
| 11.4 | Duplicate statutory action (second CN, double cancel) |
| 11.5 | Refund amount ≠ expected full refund |
| 11.6 | Unexpected CN on &lt;24h scenario |
| 11.7 | Unexpected cancellation on ≥24h scenario (original cancelled) |
| 11.8 | Duplicate outbox rows for same `idempotency_key` with conflicting outcomes |
| 11.9 | Any protected denylist invoice touched |
| 11.10 | `failed_manual` on any UAT scenario before acceptance |

Document abort reason, snapshot DB/logs, Owner notification before any retry.

---

## 12. Post-UAT

1. **Flag OFF** (§4.2) — mandatory even if scenarios incomplete.
2. **Final reconciliation** (§10) for all attempted scenarios.
3. **Evidence archive:** SQL outputs, log excerpts, CA Monthly exports, UI screenshots, provider payloads (redact secrets).
4. **Owner/CA review** meeting — go/no-go for general enablement.
5. **Decision gate:** General enablement requires companion enablement runbook + Owner checklist all approved.

---

## 13. Rollback limitations

| Action | Effect | Limitation |
| --- | --- | --- |
| Set `REFUNDS_STATUTORY_ADJUSTMENT_ENABLED=false` | Stops **new** enqueue on `RefundCompleted` | Does **not** reverse in-flight `pending` outbox (may still process until complete or fail) |
| Flag OFF | No new adjustment rows | Existing rows remain |
| Application deploy rollback | Reverts code | **Does not** undo NIC IRN cancel or issued CN IRN |
| Successful B2B &lt;24h CANCEL | IRN cancelled on NIC | **Irreversible** via flag or deploy rollback |
| Successful B2B ≥24h CN IRN | CN on NIC | **Irreversible** via application rollback |
| `failed_manual` | Terminal failure state | Requires controlled manual recovery (§8.3); refund remains completed independent of statutory outcome |

---

## Related documents

- `desk-statutory-irn-whitebooks-cancel-p-25-09-49.md` — CANCEL contract (code-derived)
- `desk-refund-statutory-adjustment-enablement-runbook-p-25-09-83.md` — general enablement checklist
- `desk-refund-statutory-adjustment-owner-approval-checklist-p-25-09-83.md` — approval gates
