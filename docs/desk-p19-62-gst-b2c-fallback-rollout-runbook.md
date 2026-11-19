# P-19-62 — GST B2C fallback rollout runbook (staged)

**Scope:** Radium Desk production only. **Not authorized** in this prompt: execution of steps below.

## Preconditions (Owner gates)

1. Production on agreed release containing PR #365 + P-19-62 (post-merge SHA).
2. Fresh encrypted backup after that release (separate Owner authorization).
3. VPS snapshot valid post-backup (`hostinger-vps-snapshot-gate`).
4. CA/Finance matrix in `docs/desk-p19-62-gst-b2c-fallback-ca-approval-matrix.md` — required rows **APPROVED**.
5. Staging/UAT database with both migrations applied.

## Migration sequence (single maintenance window)

| Order | Migration | Notes |
| --- | --- | --- |
| 1 | `2026_11_19_120000_create_commerce_order_buyer_gst_verifications_table` | **Required first**; MariaDB uses short index/FK names (`co_buyer_gst_verif_*`) |
| 2 | `2026_11_19_130000_create_commerce_order_buyer_gst_b2c_conversions_table` | FK to verifications; short names (`co_b2c_conv_*`) |
| 3 | `2026_11_19_140000_add_statutory_invoice_id_to_commerce_order_buyer_gst_b2c_conversions_table` | Links issued B2C invoice to conversion audit (optional `statutory_invoice_id`) |

**Rollback:** Dropping (2) removes conversion audit only. Dropping (1) destroys verification evidence and breaks (2). Plan forward-only on production unless explicitly authorized.

## Staged activation

| Stage | Config | Purpose |
| --- | --- | --- |
| A | Both flags **OFF** | Deploy code + migrations only; verify app health |
| B | `STATUTORY_BUYER_GSTIN_VERIFICATION_ENABLED=true` (UAT/staging first) | Provider lookups on state mismatch; still no auto B2C |
| C | Protected customer journeys on UAT | Active, Suspended, provider failure, manual conversion |
| D | `STATUTORY_BUYER_GSTIN_B2C_FALLBACK_ENABLED=true` (UAT, then prod if approved) | Automatic B2C conversion on GSTIN B2B ineligibility + audit (P-19-65); manual `approve()` optional override |
| E | Production monitoring | GST review queue volume, conversion approvals, mint B2B/B2C split |

**Keep migration authorization separate from flag activation authorization.**

## UAT journeys (minimum)

- Active GSTIN + verified Bill To → B2B mint unchanged.
- Suspended/Cancelled → automatic B2C audit row → mint without recipient GSTIN + masked remark.
- Timeout / 429 / 5xx / malformed → automatic B2C with technical remark (no false “invalid GSTIN” claim).
- Post-mint order → conversion rejected (correction/cancel flow only).
- Concurrent duplicate approval → single audit row.

## Production monitoring (after stage D)

- Count `commerce_order_buyer_gst_verifications` by `verification_status` / `review_reason`.
- Count `commerce_order_buyer_gst_b2c_conversions` by `status`.
- Alert on B2B mint attempts blocked after approved conversion.

## Blockers (current)

- Prod migrations **not** applied (P-19-57).
- CA/Finance matrix **all PENDING**.
- MariaDB concurrency test requires disposable DB on loopback non-3306 port (local CI may SKIP).