# P-17-55 — v4.1.87 pre-deployment gate (prepare only)

**Status:** Prepared — **no production deploy** in P-17-55.

## Release identity (target)

| Item | Value |
|------|--------|
| Version / tag | **v4.1.87** (metadata atop **`bd2c5e840`** after Owner tag) |
| Application merge | **#332** P-17-54 bulk hardware document download (`7e803cdb0` → `bd2c5e840`) |
| Delta | Read-only bulk POST `hardware-fulfilments/bulk/download/{labels,manifests,package}`; persisted URL/disk fetch; dashboard download UX; no Shiprocket batch generation on download paths |
| Production baseline | **v4.1.86** / **`c3d6bb969`** (P-17-50 deploy — **excludes** #332) |
| Migrations | **None** — keep **`DEPLOY_RUN_MIGRATIONS=0`** unless Owner explicitly overrides |

## Merge integrity (**VERIFIED**)

| Check | Result |
|-------|--------|
| `origin/main` HEAD | **`bd2c5e840`** merge PR **#332** |
| Feature tip | **`7e803cdb0`** |
| Prior release tag on main ancestry | **`v4.1.86`** @ **`c3d6bb969`** |

## P-17-54 regression matrix (22 cases)

| # | Case | P-17-55 status |
|---|------|----------------|
| 1 | Multiple eligible fulfilments with labels | **PASS** — `HardwareFulfilmentBulkDownloadTest::test_bulk_label_download_*` |
| 2 | Multiple existing manifests | **PASS** — distinct manifest test + dedupe test |
| 3 | Duplicate fulfilment selections | **PASS** — deduped IDs in label download test |
| 4 | Duplicate manifest IDs | **PASS** — `test_bulk_manifest_download_dedupes_identical_manifest_id` |
| 5 | Missing labels/manifests | **PASS** — `test_missing_label_and_manifest_*` |
| 6 | Mixed eligible/ineligible | **PASS** — `test_partial_package_*` + unknown id excluded |
| 7 | External carrier orders | **PASS** — local disk external label test |
| 8 | Wrong branch / unauthorized | **PASS** — branch exclusion + stranger forbidden |
| 9 | Mixed carrier selections | **NOT RUN** — no multi-provider archive merge (manifests stay separate by identity) |
| 10 | Multiple distinct manifest identities | **PASS** |
| 11 | Invalid/malicious URLs | **PASS** — SSRF host + http private IP |
| 12 | Timeout / HTTP error / oversized | **PASS** — unit fetcher (503 + oversize); timeout simulation **NOT RUN** |
| 13 | ZIP path traversal / filename collision | **PASS** — sanitized entry names; collision **NOT RUN** (low risk — AWB in name) |
| 14 | Partial archive failure | **PASS** — partial package test |
| 15 | Repeated download idempotency | **PASS** |
| 16 | Dashboard selection fulfilment IDs | **PASS** — dashboard + Vitest |
| 17 | Pagination / filter behavior | **NOT RUN** — UI unchanged except selection bar; server-side filter-wide selection **not implemented** (by design) |
| 18 | Large selection behavior | **NOT RUN** — no job queue threshold added |
| 19 | Single-order label/manifest unchanged | **PASS** — `HardwareFulfilmentOperationalWorkflowTest` in RIP bundle |
| 20 | No `createOrder` / `assignAwb` / `generate*Batch` on download | **PASS** — fake gateway counters |
| 21 | No DB writes / shipment or fulfilment events | **PASS** — event counts in label test |
| 22 | Protected shipping / pickup / mark shipped / external dispatch | **PASS** — operational workflow RIP; Cashfree authority **NOT RUN** (no code delta) |

## Security review (**VERIFIED** local)

- Download controller: `HardwareFulfilmentAccess::allowsDocumentDownload` middleware only.
- Remote fetch: HTTPS allowlist, no private IPs, redirect protocols limited to HTTPS (Laravel client options).
- No signed URLs in reconciliation JSON beyond public metadata fields already in DB.
- Branch scope via `InventoryBranchScope::assertCanOperate` on download path.

## Backup and recovery (**BLOCKED** pre-deploy)

| Check | P-17-55 result |
|-------|----------------|
| P-17-52 attested backup | **`20261117T150837Z`** `cloud_uploaded` manifest **4.1.86/c3d6bb969** |
| Covers **`bd2c5e840`** on production | **NO** — backup predates merge; feature **not** deployed |
| Hostinger snapshot **459755** | Refreshed **`2026-11-17T15:12:14Z`** (P-17-52) — **predates PR #332** |
| Fresh `bin/backup-run.sh` | **NOT RUN** — Owner/operator execution required before deploy |
| Snapshot overwrite | **BLOCKED** without explicit Owner **`AUTHORIZE SNAPSHOT OVERWRITE`** |
| GPG decrypt / restore drill | **NOT RUN** |

## Rollback

- Code/tag: **v4.1.86** / **`c3d6bb969`** (removes bulk download endpoints/UI; no schema).
- Data: no migration; no download-path DB writes expected in production after deploy.

## Deploy procedure (Owner-authorized only — **not** P-17-55)

1. Fresh encrypted backup with `cloud_uploaded` for current **v4.1.86** prod + any post-deploy mutations.
2. Re-verify or refresh Hostinger snapshot per P-17-38 / P-17-52 runbook (Owner overwrite if required).
3. Pre-deploy RIP from live `release.json` build **`c3d6bb969`** (expect **4.1.86**).
4. Tag **`v4.1.87`** on release metadata commit; `npm run build`; `php artisan release:snapshot`; `./tools/desk deploy --yes` with standard gates (`RIP_REQUIRE`, `HOSTINGER_SNAPSHOT_GATE`, etc.).
5. Post-deploy: `release.json` **4.1.87** / deploy build SHA; `/up`, `/login`; queue worker **RUNNING**; UAT per `docs/desk-p17-55-v4187-uat.md`.
6. Smoke: Hardware workspace — select rows, **Download labels** (existing URLs only), confirm **no** new Shiprocket labels in provider audit.

## RIP readiness

| Item | Status |
|------|--------|
| Pre-deploy RIP from prod **v4.1.86** | **PREPARED** — execute at deploy task |
| Post-deploy RIP | **NOT RUN** |
| `rip:verify-checkpoint` on fresh capture | **NOT RUN** |

## Protected approval

**P-17-50** authorized **v4.1.86** deploy only. **v4.1.87** requires a **new** Owner deployment authorization (separate from P-17-55).