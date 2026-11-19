# P-19-68 — UAT remediation and GST readiness (execution plan)

**Baseline code:** `origin/main` @ **`02809c7f9`** (includes P-19-65 automatic B2C)  
**UAT app path:** `/var/www/radium-desk-uat`  
**UAT database:** `radium_desk_uat`  
**Production (forbidden target):** `/var/www/radium-desk` · `radium_desk`

**Status (P-19-68 session):** **`UAT BLOCKED — REMEDIATION REQUIRED`** — no Owner-authorized UAT backup, deploy, migration, or integration mutations were executed.

---

## Live re-verification (2026-11-19, SSH metadata only)

| Item | Production | UAT |
| --- | --- | --- |
| `APP_ENV` | `production` | `staging` |
| `DB_DATABASE` | `radium_desk` | `radium_desk_uat` |
| GST buyer tables | **0** | **0** |
| GST migrations `11_19_12/13/14` | **none** | **none** |
| GST flags in `.env` | unset (OFF) | unset (OFF) |
| `desk deploy` default target | **`/var/www/radium-desk`** (`tools/config.sh`) | Use **`desk deploy-kvm-uat`** only |

Prod backup staging under `/var/backups/radium-desk/` exists; **no** dedicated `/var/backups/radium-desk-uat/` listing observed.

---

## Atomic plan (Owner gate on each mutation)

| Step | Action | Target | Owner approval | P-19-68 executed |
| --- | --- | --- | --- | --- |
| 1 | UAT mysqldump backup → `/var/backups/radium-desk-uat/runs/<id>/` | `radium_desk_uat` only | **Required** | **NO** |
| 2 | Restore drill → disposable schema e.g. `radium_desk_uat_drill_<id>` | not `radium_desk` | **Required** | **NO** |
| 3 | Deploy `main` ≥ `02809c7f` via `desk deploy-kvm-uat --dry-run` then `--yes` | `/var/www/radium-desk-uat` | **Required** | **NO** |
| 4 | `php artisan migrate --force` with verified `DB_DATABASE=radium_desk_uat` | UAT `.env` only | **Required** | **NO** |
| 5 | Post-migrate table check | UAT schema | — | **NO** |
| 6 | Email: `MAIL_MAILER=log` or approved sink; prove no ZeptoMail route | UAT `.env` | **Required** | **NO** |
| 7 | WhiteBooks: Owner-approved sandbox or no-GSP strategy | UAT `.env` | **Required** | **NO** |
| 8 | TLS/DNS or approved private access | Infrastructure | **Required** | **NO** |
| 9 | UAT GST flags ON for test window only | UAT `.env` | **Required** | **NO** |
| 10 | Synthetic GST matrix execution | UAT | **Required** | **NO** |

---

## Migration order (UAT only)

1. `2026_11_19_120000_create_commerce_order_buyer_gst_verifications_table`
2. `2026_11_19_130000_create_commerce_order_buyer_gst_b2c_conversions_table`
3. `2026_11_19_140000_add_statutory_invoice_id_to_commerce_order_buyer_gst_b2c_conversions_table`

---

## WhiteBooks UAT (Owner decision — not executed)

| Option | Notes |
| --- | --- |
| A | No GSP on UAT — provider unconfigured paths + automatic B2C technical remarks |
| B | Dedicated sandbox URL/credentials (written approval) |
| C | Future in-app test double |

Production GSP must **not** be copied to UAT without explicit approval.

---

## Deploy safety

- Default **`desk deploy`** → **production** path.
- **`desk deploy-kvm-uat`** (`tools/commands/deploy-kvm-uat.sh`) hard-locks `/var/www/radium-desk-uat`.

See also: `docs/desk-p19-67-uat-environment-discovery.md`, `docs/desk-p19-62-gst-b2c-fallback-rollout-runbook.md`.