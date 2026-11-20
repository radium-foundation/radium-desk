# P-20-23 — Release preparation (no deploy)

**Target deploy SHA:** merge commit on `main` (post-PR).  
**Production baseline before deploy:** **v4.1.103** / `befc58e95`.

## Migrations

**None.**

## Feature flags

| Flag | Default after deploy | Activation |
| --- | --- | --- |
| `STATUTORY_BUYER_GSTIN_VERIFICATION_ENABLED` | Unchanged (Stage A ON on prod) | Existing runbook |
| `STATUTORY_BUYER_GSTIN_B2C_FALLBACK_ENABLED` | **OFF** | Owner Stage B only — enables automatic B2C for **confirmed** ineligibility |

## Behaviour change (when fallback ON)

- Automatic B2C without manual `approve()` for confirmed inactive/invalid GSTIN outcomes.
- Provider timeout/rate-limit/5xx **hold**; exceptional manual B2C only.
- Hardware path: blank invoice GSTIN after approved auto-conversion; conversion linked post-mint.

## Recovery

Use backup **`20261120T050954Z`** (pre **v4.1.103**) or latest Owner PRE-RIP before deploy. Rollback: redeploy prior tag; no schema rollback.

## Post-deploy checks (Owner)

1. Flags unchanged until Stage B authorized.
2. RBP2295 / order 23661 still un-invoiced.
3. Focused GST bundle + MariaDB concurrency on approved SHA (Owner Terminal if full suite required).