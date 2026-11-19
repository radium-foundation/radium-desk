# P-19-65 — Automatic GSTIN failure-to-B2C

**ChatGPT ID:** P-19-65  
**Cursor ledger ID:** `bd0ac5bd-88f0-48e0-aacd-66eca9be9aef`  
**Desk ledger row:** `RadiumDesk-P-19-65`  
**Baseline:** `origin/main` @ `58e3ac0f4` (PR #368)

## Summary

- `ensureAutomaticConversion()` replaces manual approval as runtime prerequisite when B2C fallback flag is on.
- Hooked from verification persist, `ServiceStatutoryGstB2bClassification`, and `CommerceBuyerGstMintGate`.
- Technical provider failures → B2C with safe remarks (no false “invalid GSTIN” claims).

## Tests

| Suite | Result |
| --- | --- |
| GST B2C focused PHPUnit | **31/31** |
| MariaDB concurrency | **2/2** (~138s local) |