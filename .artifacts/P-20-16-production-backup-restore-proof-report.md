# P-20-16 — Production backup and disposable restore proof

| Field | Value |
| --- | --- |
| Task | **P-20-16** |
| Cursor ledger | `bd0ac5bd-88f0-48e0-aacd-66eca9be9aef` |
| Desk ledger row | **RadiumDesk-P-20-16** |
| Risk | **HIGH** |
| Start (IST) | **2026-11-20 09:48:11** |
| End (IST) | **2026-11-20 09:55:40** |
| Duration | **~7 min 29 s** (backup **~115 s**, restore **306 s**, verify/compare **~15 s**) |

## Verdict

**PASSED** — Fresh encrypted backup at **`339e3dc99`**, integrity verified, disposable restore **`radium_desk_p2016_drill`** structural match. **No production application/data mutation** on `radium_desk`.

---

## Production identity (pre/post — VERIFIED)

| Item | Value |
| --- | --- |
| Path | `/var/www/radium-desk` |
| Release | **v4.1.102** / **`339e3dc99`** |
| `DB_DATABASE` | **`radium_desk`** |
| GST verification | **OFF** |
| GST B2C fallback | **OFF** |
| Migrations **66–68** | **Ran** |
| POST-RIP `…0843…339e3dc` | **PASS** (unchanged) |

---

## Backup

| Field | Value |
| --- | --- |
| **backup_id** | **`20261120T041822Z`** |
| Created (UTC) | **2026-11-20T04:18:22Z** (~**09:48:22 IST**) |
| Phase | **`cloud_uploaded`** |
| Local path | `/var/backups/radium-desk/runs/20261120T041822Z/` |
| Database | **`radium_desk`** (MariaDB **11.8.8**) |
| Application (manifest) | **4.1.102 / 339e3dc99** |
| `database.sql.gz.gpg` size | **352,333,126** bytes |
| SHA-256 (database artifact) | **`1f21c44b8fbf38b1d1b6a537af44f72507ea495228ca337100052f2a87b7e3a9`** |
| On-disk SHA vs manifest | **PASS** (database + secrets artifacts) |
| Cloud upload | **completed**, `artifacts_verified: true` |
| Scheduler exit code | **0** |
| Operator log (local) | `/tmp/p20-16-backup.log` |

**Command (sanitized):**

```bash
ssh deskvps "sudo bash -c 'set -a; source /root/.radium-backup.env; set +a; \
  export PHP_BIN=/usr/local/lsws/lsphp84/bin/php; cd /var/www/radium-desk && \
  BACKUP_LOCK_FILE=/var/lock/radium-desk-backup.lock BACKUP_SCHEDULE_SKIP_LOCK=true ./bin/backup-schedule.sh'"
```

---

## Restore drill

| Field | Value |
| --- | --- |
| Disposable schema | **`radium_desk_p2016_drill`** |
| Source backup | **`20261120T041822Z`** |
| Pre-restore SHA check | **PASS** |
| Import exit code | **0** |
| Duration | **306 s** |
| Tables imported | **200** |
| **Drill retained** | **YES** (not dropped) |
| Operator log (local) | `/tmp/p20-16-restore.log` |

**Reproduction pattern (Owner DR — never import into `radium_desk` without separate auth):**

1. Verify manifest SHA-256 matches `database.sql.gz.gpg` on disk.
2. `DROP DATABASE IF EXISTS \`radium_desk_p20XX_drill\`; CREATE DATABASE … utf8mb4_unicode_ci;`
3. Decrypt via `gpg` using passphrase file on host (`/root/.radium-backup.env` — **not** documented in reports).
4. `gpg --decrypt database.sql.gz.gpg | gunzip | mysql radium_desk_p20XX_drill`
5. Structural compare vs live `radium_desk` (counts only).

---

## Structural comparison (`radium_desk` vs `radium_desk_p2016_drill`)

| Metric | Production | Drill | Match |
| --- | ---: | ---: | --- |
| Tables | **200** | **200** | **YES** |
| Migration rows | **229** | **229** | **YES** |
| Last migration | `2026_11_19_140000_*` | **same** | **YES** |
| Foreign keys | **415** | **415** | **YES** |
| Index statistics rows | **1234** | **1234** | **YES** |
| `commerce_order_buyer_gst_verifications` | exists | exists | **YES** |
| `commerce_order_buyer_gst_b2c_conversions` | exists | exists | **YES** |
| `radiumbox_statutory_receipt_publications` | exists | exists | **YES** |

**Limits:** Row-level / customer data **not compared** (metadata-only). Does not prove GST runtime correctness.

---

## Preservation

| Item | Status |
| --- | --- |
| Prior backups (`20261120T025830Z`, etc.) | **Preserved** |
| PRE/POST RIP checkpoints | **Unchanged** |
| Drill `radium_desk_p2009_drill` | **Retained** (if present) |
| Drill `radium_desk_p2016_drill` | **Retained** |

---

## Production mutations

| Action | Status |
| --- | --- |
| `radium_desk` data/schema | **NO change** |
| Deploy / `.env` / flags | **NO — Not performed** |
| Live GST provider / mint / IRN / receipt | **NO — Not performed** |
| New RIP capture | **NO — Not performed** |

---

## Results summary

| Step | Result |
| --- | --- |
| VERIFY baseline | **PASSED** |
| PREFLIGHT disk (~182 GiB free) | **PASSED** |
| BACKUP | **PASSED** (exit 0) |
| VERIFY backup integrity | **PASSED** |
| RESTORE drill | **PASSED** (exit 0) |
| STRUCTURAL compare | **PASSED** |
| Log secret review (local logs) | **PASSED** (no passphrase material) |

---

## Remaining risks

1. Restore proof is **structural** — not a full application cutover test.
2. Drill DB on shared MariaDB instance — must keep Laravel `.env` on **`radium_desk`** only.
3. GST Stage A still requires **separate Owner flag authorization** (P-20-15).

---

## Next Owner decision

Authorize **Stage A GST verification flag** per `docs/desk-p20-15-controlled-production-gst-activation-readiness.md`, referencing backup **`20261120T041822Z`** as the current post-**`339e3dc99`** recovery point.