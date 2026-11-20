# P-20-27 — Permanent confirmed ineligibility → B2C (release prep, no deploy)

**Baseline production:** v4.1.103 / `befc58e95`  
**Target code:** merge of P-20-27 (after P-20-23 on `main`).

## Configuration (no prod change required for core rule)

| Flag | Production expected | After deploy |
| --- | --- | --- |
| `STATUTORY_BUYER_GSTIN_VERIFICATION_ENABLED` | `true` (Stage A) | Unchanged |
| `STATUTORY_BUYER_GSTIN_B2C_FALLBACK_ENABLED` | `false` (Stage B) | **Unchanged** — confirmed ineligibility auto-B2C no longer depends on this flag |

Stage B fallback remains the gate for **manual** `approve()` / `reject()` on provider-uncertainty outcomes only.

## Behaviour

- **Confirmed** suspended/cancelled/invalid GSTIN outcomes → `ensureAutomaticConversion` on all mint paths (hardware, service, manual, post-serial, reconciliation).
- **Provider uncertainty** (timeout, pending, etc.) → hold; no automatic B2C.
- B2C invoices: blank `buyer_gstin` on mint; audit row retains submitted GSTIN and provider evidence.

## RBP2295 (post-deploy only, Owner authorization)

Order **23661** / **RBP2295**: `inactive_registration` / Suspended — expected to resolve to **one** B2C hardware invoice when mint is attempted after deploy, with fresh evidence re-check.

## Rollback

Redeploy prior tag; no migrations. Prior backup **`20261120T083004Z`** @ v4.1.103.