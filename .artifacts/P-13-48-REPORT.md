# P-13-48 — Report (STOP)

| Field | Value |
| --- | --- |
| Global ID | **P-13-48** |
| Cursor ledger | **RadiumDesk-P-13-48** |
| Start | 2026-11-13 ~15:39 IST |
| End | 2026-11-13 ~15:48 IST |
| Baseline SHA | **`51ee6d7e2c185bc61383636969b0e1c975ecf962`** |

## Outcome

Canonical register reconciled against merge SHAs **#241–#245** and Owner exclusions. **No application code.**

## Totals (228 rows)

| Bucket | Count |
| --- | ---: |
| VERIFIED FIXED | 222 |
| STILL FAILING (active) | 4 |
| OWNER EXCLUDED | 2 |
| ENVIRONMENT BLOCKED | 0 |
| UNKNOWN | 0 |
| **Active remediation** | **4** |

**Arithmetic:** 222 + 4 + 2 = 228.

## Validation

| Check | Exit | Result |
| --- | ---: | --- |
| Spot PHPUnit (rows 10,15,125,189,202) | 0 | 6/6, 63 assert, 7660 ms |
| Full suite | — | **NOT RUN** |
| Production / deploy / external | — | **NO — Not performed** |
| Wallet `d9481615` | — | **Preserved** |

See `.artifacts/P-13-48-REGISTER-RECONCILE.md` for row-by-row diff.
