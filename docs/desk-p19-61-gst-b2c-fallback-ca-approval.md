# P-19-61 — Buyer GSTIN B2C fallback (CA/Finance approval boundary)

**ChatGPT ID:** P-19-61  
**Status:** Implementation + tests only — **not** statutory rollout-ready.

## Proposed conversion rule (engineering)

| Provider outcome | Desk behaviour (verification ON, fallback OFF) | After explicit approved conversion (fallback ON) |
| --- | --- | --- |
| Active + verified Bill To | B2B (unchanged) | N/A — conversion not offered |
| Provisional / mismatch / provider outage / timeout / 429 / 5xx / malformed | GST review / hold — **never auto B2C** | Conversion **not eligible** (hold path) |
| Suspended / Cancelled / confirmed invalid registration | GST review | **Eligible** for manual B2C conversion with audit + masked remark |

Submitted `buyer_gstin` on the commerce order is **never cleared**; only the statutory mint recipient GSTIN field is omitted on authorized B2C.

## CA/Finance decisions still required (written sign-off)

1. Whether Suspended or Cancelled **recipient** GSTIN may be treated as B2C for the supplier's outward supply.
2. Whether customer confirmation or acceptance is required before conversion.
3. Approved invoice remark wording (default in code is a placeholder pending approval).
4. Place-of-supply and tax treatment for converted invoices.
5. Whether any transaction must **hold** rather than convert (e.g. Provisional registration).

## Configuration (default OFF)

- `STATUTORY_BUYER_GSTIN_VERIFICATION_ENABLED` — unchanged default `false`.
- `STATUTORY_BUYER_GSTIN_B2C_FALLBACK_ENABLED` — new, default `false`.

Production must not enable either flag until migration, UAT, and CA/Finance approval.

## Migration dependency

Requires `2026_11_19_120000_create_commerce_order_buyer_gst_verifications_table` before  
`2026_11_19_130000_create_commerce_order_buyer_gst_b2c_conversions_table`.  
Rollback of the conversions table is non-destructive to commerce orders; verification table rollback drops audit evidence.