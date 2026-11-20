# P-19-65 — Automatic GSTIN failure-to-B2C classification

**Scope:** Runtime when `STATUTORY_BUYER_GSTIN_B2C_FALLBACK_ENABLED=true` (still default **OFF**).

## B2B allowed (no automatic conversion)

| Outcome | B2B | Auto B2C |
| --- | --- | --- |
| Verified **Active** or **Provisional** with Bill To + GSTIN/billing state alignment | Yes | No |
| Matching billing/GSTIN states with verification policy disabled | Yes | No |
| Order has no buyer GSTIN | No (native B2C) | N/A |

## Automatic B2C (conversion audit row; blank invoice `buyer_gstin`)

| Provider / validation outcome | Remark class |
| --- | --- |
| `inactive_registration` (Suspended, Cancelled, …) | Factual registration status |
| `invalid_gstin_format`, `invalid_gstin_response` | Format / provider validation |
| `incomplete_registration_snapshot`, `registration_address_incomplete`, `gstin_state_mismatch` | Verification snapshot facts |
| Local `gstin_invalid` / `gstin_incomplete` (no provider run) | Format check only |
| `provider_timeout`, `provider_unavailable`, `provider_rate_limited` | **Hold** — GST review; exceptional manual B2C only (`authorized_by` set) |
| `provider_malformed_response`, `provider_error` | **Hold** — same as above |
| `verification_not_run`, `verification_provider_unconfigured` | **Hold** — same as above |
| `Pending` verification at mint | **Hold** — no automatic conversion (P-20-23) |

## Not changed

- Post-mint conversion still rejected.
- B2B mint blocked after approved conversion.
- Original `commerce_order.buyer_gstin` and verification rows preserved.
- Manual `approve()` remains for documented override; `authorized_by` null for automatic rows.

## Email-log confirmation

Still **GAP** — see `docs/desk-p19-64-gst-b2c-email-log-gap.md`.