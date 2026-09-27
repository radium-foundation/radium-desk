# Production enablement runbook — refund statutory adjustment

**Prompt ID:** RadiumDesk-P-25-09-83  
**Date:** 2026-09-27  
**Feature:** Post-refund automatic statutory adjustment (v4.0.151+)  
**Config key:** `REFUNDS_STATUTORY_ADJUSTMENT_ENABLED` → `config('refunds.statutory_adjustment.enabled')`  
**Default:** `false`  
**Status:** PLAN ONLY — execute only after controlled UAT and Owner checklist complete.

---

## Evidence gate (do not skip)

General enablement requires **live provider proof** from authorized UAT:

| Contract | Live proof required |
| --- | --- |
| WhiteBooks CANCEL (B2B &lt;24h refund path) | **NOT VERIFIED** until UAT Scenario A |
| WhiteBooks CRN GENERATE (B2B ≥24h refund path) | **NOT VERIFIED** until UAT Scenario B |

Code-derived behavior and PHPUnit `Http::fake` tests are **not** sufficient alone.

---

## 1. Preflight

- [ ] Production `storage/app/private/release.json`: version ≥ **4.0.151**, build ≥ **7d565746**
- [ ] Migration `refund_statutory_adjustments` table exists
- [ ] Controlled UAT runbook executed and signed off (companion doc)
- [ ] Owner approval checklist: all gates **APPROVED**
- [ ] Backup `.env` and database per project backup policy
- [ ] Protected-invoice denylist reviewed (no planned changes to listed IDs)
- [ ] CA/GST practitioner notified of enablement date
- [ ] Reconciliation SQL from UAT runbook §9 prepared and saved
- [ ] On-call / escalation contacts confirmed (placeholders below)

**Escalation placeholders** (replace before enablement — not documented in repo):

| Role | Contact |
| --- | --- |
| Owner | _[TBD]_ |
| Finance lead | _[TBD]_ |
| CA/GST practitioner | _[TBD]_ |
| Infrastructure / KVM operator | _[TBD]_ |

---

## 2. Flag change (enable)

1. Backup `.env` → `.env.bak-refund-statutory-enable-<UTC>`
2. Set `REFUNDS_STATUTORY_ADJUSTMENT_ENABLED=true`
3. On production KVM:
   ```bash
   cd /var/www/radium-desk
   php artisan config:clear
   php artisan config:cache
   ```
4. Record enablement timestamp and operator name

---

## 3. Cache / runtime verification

```bash
php artisan tinker --execute="echo json_encode([
  'release' => json_decode(file_get_contents(storage_path('app/private/release.json')), true),
  'flag' => config('refunds.statutory_adjustment.enabled'),
  'provider' => config('statutory_invoices.einvoice.provider'),
  'base' => config('statutory_invoices.einvoice.gsp_base_url'),
]);"
```

**Expect:** `flag` = true; provider/base unchanged from pre-enablement values.

Optional: `curl -s -o /dev/null -w "%{http_code}" https://<desk-host>/up` → **200**

---

## 4. Smoke verification (non-mutating)

- [ ] Complete a **partial** refund on a non-UAT order **or** verify listener does not fire on flag-off replay — **preferred:** confirm no new rows:
  ```sql
  SELECT COUNT(*) FROM refund_statutory_adjustments WHERE created_at >= :enable_time;
  ```
  After first real full refund post-enablement, verify card appears on refund show UI.

- [ ] Confirm scheduler processing outbox:
  ```sql
  SELECT COUNT(*) FROM outbox_events
  WHERE event_type = 'refund.statutory_adjustment' AND status = 'pending';
  ```

**Do not** trigger a production full refund solely for smoke unless Owner authorizes a disposable order.

---

## 5. Monitoring window

**Duration:** _[TBD — Owner sets, e.g. first 72 hours]_

| Check | Frequency | Action threshold |
| --- | --- | --- |
| `failed_manual` count | Every _[TBD]_ | &gt; 0 → escalate Finance immediately |
| `failed_retryable` &gt; 30m old | Hourly | Investigate outbox / provider |
| `pending` adjustments | Hourly | Stuck &gt; 30m → check scheduler logs |
| `outbox_events` failed for `refund.statutory_adjustment` | Hourly | Any → triage §8 UAT runbook |
| Application log listener errors | Daily | `[Refund] Statutory adjustment enqueue listener failed` |

SQL dashboard (copy from UAT runbook §9.1).

---

## 6. First-period reconciliation

Within _[TBD days]_ of enablement:

- [ ] CA Monthly export for period covering first adjustments
- [ ] Cross-check: each `succeeded` adjustment has matching invoice cancel or CN per policy
- [ ] Zero unexpected `failed_manual` without documented resolution
- [ ] POS refunds show `not_applicable` / `pos_statutory_boundary` where expected
- [ ] Partial refunds show `partial_refund` skip — no statutory mutation
- [ ] Owner + Finance sign first-period reconciliation

---

## 7. Failure escalation

| Severity | Condition | Escalate to |
| --- | --- | --- |
| **P1** | `failed_manual` on production refund | Owner + Finance + CA _[TBD]_ |
| **P1** | Wrong invoice cancelled / CN issued | Owner + CA immediately; consider flag OFF |
| **P2** | Sustained `failed_retryable` / provider 5xx | Infrastructure + Finance |
| **P3** | Single transient retry succeeded | Log only |

Do not retry provider calls manually without IRN state reconciliation (UAT runbook §8).

---

## 8. Disable procedure

1. Set `REFUNDS_STATUTORY_ADJUSTMENT_ENABLED=false` (or remove key)
2. `php artisan config:clear && php artisan config:cache`
3. Verify flag false via tinker
4. Record disable timestamp and reason
5. Notify Owner/Finance

---

## 9. Post-disable handling

| State | Behavior after flag OFF |
| --- | --- |
| `pending` + outbox pending | May still process until success/failure — monitor |
| `failed_retryable` | Outbox may continue retries until max attempts |
| `failed_manual` | Unchanged — requires manual recovery |
| `succeeded` | Permanent — no automatic reversal |
| New refunds | No new adjustment rows enqueued |

After disable, run §9 SQL from UAT runbook to confirm no new enqueues.

---

## 10. Rollback limitations (summary)

- Flag OFF prevents **new** adjustments only.
- NIC IRN cancellation and CN IRN issuance are **irreversible** by deploy or flag rollback.
- See UAT runbook §13 for full detail.

---

## Related documents

- `desk-refund-statutory-adjustment-controlled-uat-runbook-p-25-09-83.md`
- `desk-refund-statutory-adjustment-owner-approval-checklist-p-25-09-83.md`
