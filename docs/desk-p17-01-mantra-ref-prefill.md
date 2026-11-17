# P-17-01 — Mantra RD expiry in Service Reference input

**Cursor ledger:** RadiumDesk-P-17-01

Prefill Mantra `rd_expiry_date` as `d/m/Y` in the existing REF **Service Reference** text field (caret at 0). Operator types `AW- ` prefix. Subscription expiry for `complete-assignment` remains in-memory `proposedRdExpiryYmd` (not parsed from the text field). No date picker. Desk `visibilityState === 'visible'` required before open/focus.