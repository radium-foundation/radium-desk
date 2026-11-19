# P-19-67 — UAT discovery report (summary)

| Field | Value |
| --- | --- |
| Start / end (IST) | ~22:18 / ~22:35 |
| Duration | ~17 min |
| Merge baseline | `origin/main` @ **`bdef42ce7`**; P-19-63/64/65/66 ancestry **YES** |

## Decision

**NOT APPROVED FOR FUTURE GST UAT (P-19-68)** on KVM UAT until deploy + migrations + integration plan + backup/TLS gates.

## Evidence sources

- SSH metadata only (`APP_*`, `DB_*`, `MAIL_*`, schema names, loopback HTTP)
- Public DNS `dig`
- No production table data reads
- No infrastructure mutations

## Candidate summary

| Candidate | DB isolation | Approved for GST UAT |
| --- | --- | --- |
| `/var/www/radium-desk-uat` + `radium_desk_uat` | **VERIFIED** (distinct schema) | **NO** — stale, no GST schema, TLS/DNS/backup gaps |
| Local MariaDB 33118 | **VERIFIED** | **Concurrency only** |
| PHPUnit local | N/A | **Regression only** (P-19-66) |

Full detail: `docs/desk-p19-67-uat-environment-discovery.md`.