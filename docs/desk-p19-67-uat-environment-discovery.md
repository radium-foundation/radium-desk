# P-19-67 — Radium Desk UAT environment discovery and isolation

**ChatGPT ID:** P-19-67  
**Cursor ledger ID:** `bd0ac5bd-88f0-48e0-aacd-66eca9be9aef`  
**Investigation:** 2026-11-19 ~22:18–22:35 IST  
**Risk:** HIGH (production adjacency, statutory integrations)

## Executive decision

| Decision | |
| --- | --- |
| **Primary candidate** | KVM **`/var/www/radium-desk-uat`** → schema **`radium_desk_uat`** |
| **P-19-68 GST synthetic UAT** | **NOT APPROVED** (blocked until remediation below) |
| **Alternate (dev-only)** | Local PHPUnit + disposable MariaDB **33118** (regression only, not statutory journey UAT) |

Isolation of **database name** is **VERIFIED**; **hosting and integration** isolation is **partial**; **readiness** for automatic GST B2C is **BLOCKED** (stale code, missing migrations, no public/trusted URL, no UAT backup evidence).

---

## Verified production baseline (read-only, 2026-11-19)

| Item | Value |
| --- | --- |
| Host | Hostinger KVM **`187.127.129.16`** (`srv1910783`) |
| App path | `/var/www/radium-desk` |
| Public URL | `https://desk.radiumbox.com` (Cloudflare A) |
| `APP_ENV` | `production` |
| `DB_DATABASE` | `radium_desk` on `127.0.0.1` |
| Queue | `redis` + supervisor **`radium-desk-queue-worker`** (RUNNING) |
| Mail | `smtp` / ZeptoMail configured (**customer-capable**) |
| Statutory GST flags | **unset** (config default **OFF**) |
| WhiteBooks GSP | **Configured** on prod (`STATUTORY_EINVOICE_GSP_BASE_URL=https://api.whitebooks.in`, provider `whitebooks`) |

No customer/order/GST table queries were executed on production.

---

## Candidate A — KVM `radium-desk-uat` (staging)

| Attribute | Finding | Class |
| --- | --- | --- |
| **Owner** | Radium / Hostinger KVM (same VPS as prod) | **VERIFIED** (P-03-06 + SSH) |
| **Purpose** | Desk staging / wallet UAT history (P-04-10-122/123) | **VERIFIED** (ledger) |
| **Deploy path** | `/var/www/radium-desk-uat` | **VERIFIED** SSH |
| **Path mtime** | **2026-10-14** (tree older than P-19-65 code) | **VERIFIED** |
| **CHANGELOG size** | ~124 KiB vs prod ~250 KiB | **INFERRED** stale vs prod |
| **Public URL** | `http://desk-uat.radiumbox.com` in `.env` | **VERIFIED** `.env` |
| **Public DNS** | **No A/AAAA** in public resolver for `desk-uat.radiumbox.com` | **VERIFIED** `dig` |
| **Loopback HTTP** | `Host: desk-uat.radiumbox.com` → **200** on `127.0.0.1` | **VERIFIED** SSH curl |
| **`APP_ENV`** | `staging` | **VERIFIED** |
| **`DB_DATABASE`** | `radium_desk_uat` | **VERIFIED** |
| **`DB_HOST`** | `127.0.0.1` (shared MariaDB instance with prod) | **VERIFIED** |
| **Schema exists** | `radium_desk` and `radium_desk_uat` both present | **VERIFIED** `information_schema` |
| **GST migrations/tables** | **None** (`commerce_order_buyer_gst_*` absent; no matching migration rows) | **VERIFIED** |
| **Queue** | `sync` (no async worker) | **VERIFIED** — **lower** cross-env queue risk |
| **Mail** | `MAIL_MAILER` **empty**; no SMTP block in sampled keys | **VERIFIED** — **likely** no live customer mail |
| **Statutory / WhiteBooks env** | **No** `STATUTORY_*` / GSP keys in UAT `.env` | **VERIFIED** |
| **Supervisor worker** | **One** `radium-desk-queue-worker` (prod tree only) | **VERIFIED** |
| **Release metadata** | `release.json` **missing** on both prod and UAT paths | **VERIFIED** |
| **Dedicated UAT DB backup dir** | No `radium_desk_uat` under `/var/backups/` | **VERIFIED** listing |
| **UAT backup scripts** | Present under UAT `bin/backup-*.sh` | **VERIFIED** |
| **HTTPS / TLS** | Public HTTPS to `desk-uat` **failed** from operator network; ledger documents listener/SAN gaps | **INFERRED BLOCKED** (P-12-10-082/083) |

### Production separation (Candidate A)

| Control | Separation | Class |
| --- | --- | --- |
| Database name | `radium_desk_uat` ≠ `radium_desk` | **VERIFIED** |
| Credentials | Separate DB user possible; **not** read (no credential dump) | **UNKNOWN** |
| Writable cross-schema | Same MariaDB **instance** — risk if credentials mis-scoped | **INFERRED** mitigated by distinct schema + least privilege **if** enforced |
| Queue | UAT `sync` vs prod `redis` worker | **VERIFIED** not shared queue consumer |
| Mail | UAT mail unset vs prod ZeptoMail | **VERIFIED** partial |
| WhiteBooks | UAT unset vs prod production API URL | **VERIFIED** UAT not configured |
| App filesystem | Sibling directories on same host | **VERIFIED** — deploy discipline required |
| DNS | Prod public; UAT name **not** in public DNS | **VERIFIED** reduces accidental public UAT |

**Shared-host caveat:** UAT and prod on **one KVM** — whole-VM snapshot, mistaken `rsync` target, or shared MariaDB root remain **INFERRED** operational risks (not eliminated by naming).

---

## Candidate B — Local disposable MariaDB (`127.0.0.1:33118`)

| Attribute | Finding |
| --- | --- |
| Database | `radium_desk_buyer_gst_b2c_conversion_concurrency_test` |
| Purpose | P-19-63 concurrency harness only |
| Isolation from prod | **VERIFIED** loopback + gated name |
| Statutory journey UAT | **NOT SUITABLE** (no Desk app, no HTTP mint) |

---

## Candidate C — Legacy Cloud `u215544208_desk`

| Attribute | Finding |
| --- | --- |
| Role | Legacy Desk on shared hosting | **VERIFIED** P-03-06 |
| GST UAT | **REJECTED** — not current Desk; not isolated prod substitute |

---

## P-19-68 execution plan

See **`docs/desk-p19-68-uat-remediation-execution-plan.md`** (numbered gates, Owner approvals). P-19-68 ended **`UAT BLOCKED`** — no live remediation executed.

## P-19-68+ future UAT setup (required before GST flag-on tests)

1. **Owner-authorized deploy** of `main` ≥ **`dfe82ffcf`** to `/var/www/radium-desk-uat` only (no prod rsync).
2. **Migrate** `radium_desk_uat` only: `120000` → `130000` → `140000` (GST verification + B2C conversion).
3. **Configure UAT-only** env: enable verification + B2C fallback **on UAT path only**; WhiteBooks **sandbox or Http-fake strategy** — **do not** copy prod GSP secrets without Owner approval.
4. **TLS/DNS:** resolve P-12-10-083 (public DNS + vhost HTTPS) or document VPN/hosts-only access with Owner acceptance.
5. **Backup:** prove UAT backup/restore (runbook or snapshot) before destructive synthetic mint tests.
6. **Synthetic data only:** commerce orders + provider fixtures; never **23163** / **RBP2295**.
7. **MariaDB concurrency:** keep using disposable DB **33118** off prod (policy).

---

## Acceptance matrix linkage

Repository matrix: `docs/desk-p19-66-gst-auto-b2c-uat-acceptance-matrix.md`. **Live column** remains **BLOCKED** until steps above complete.

---

## References

- `RADIUMWEBSITES_INFRASTRUCTURE_DISCOVERY.md` (P-03-06)
- Ledger: P-04-10-122/123, P-12-10-082/083, P-19-66
- `tools/config.sh` production path