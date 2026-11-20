# P-20-21 — Production deployment: hardware GST mint gate (v4.1.103)

| Field | Value |
| --- | --- |
| Task | **P-20-21** |
| Cursor ledger | `bd0ac5bd-88f0-48e0-aacd-66eca9be9aef` |
| Desk ledger row | **RadiumDesk-P-20-21** |
| Risk | **HIGH** |
| Start (IST) | **2026-11-20 10:39:46** |
| End (IST) | **2026-11-20 10:45:24** |
| Duration | **~5 min 38 s** (backup **~160 s** prior; deploy **~62 s**) |

## Verdict: **PASSED** (smoke-verified only; **not known-good**)

---

## Source identity

| Item | Value |
| --- | --- |
| Intended fix merge | **`3f06cf116`** (PR **#384**) |
| Release branch | **`release/p20-21-v4103`** |
| Release tag | **`v4.1.103`** |
| **Deployed `release.json` build** | **`befc58e95`** (merge **+** CHANGELOG entry; **includes** PR #384 code) |
| Pre-production build | **`339e3dc99`** / **v4.1.102** |

### PR #384 diff review (VERIFIED)

| File | Change |
| --- | --- |
| `CommerceBuyerGstMintGate.php` | **`assertNotBlocking()`** |
| `StatutoryInvoiceService.php` | Gate before hardware `issue()` |
| `HardwareFulfilmentInvoiceService.php` | Gate before mint in transaction |
| Tests + artifacts | **No prod runtime effect** |

No migrations. No `.env` changes in this deploy.

---

## Recovery evidence

| Item | Class | Value |
| --- | --- | --- |
| Legacy recovery point | **VERIFIED applicable** | **`20261120T041822Z`**, DB SHA **`1f21c44b…e3a9`** @ build **`339e3dc99`** (P-20-16 drill) |
| Pre-deploy backup | **VERIFIED** | **`20261120T050954Z`**, **`cloud_uploaded`**, app **`339e3dc99`** |
| DB SHA (fresh backup) | **VERIFIED** | **`39af0435365c0625a6eef1a2a49da78d69799c2fd55859fa553e735a6e66c572`** |
| Pre-deploy RIP | **PASS** | **`RIP-201126-1042-radium-desk-pre-p20-21-hardware-mint-gate-339e3dc-339e3dc`** |
| Post-deploy RIP | **PASS** | **`RIP-201126-1044-radium-desk-post-p20-21-hardware-mint-gate-v4103-befc58e`** |

Restore drill on **`20261120T050954Z`**: **NOT RUN** (Owner exception pattern per recent GST tasks).

---

## Deployment

| Step | Result |
| --- | --- |
| `desk deploy --yes` | **Exit 0** (~**62 s**) |
| Log | `/tmp/p20-21-deploy-live.log` |
| `DEPLOY_RUN_MIGRATIONS` | **0** (post-sync migrations **skipped** via deploy-post-sync opt-in) |
| RIP gate | **`RIP_REQUIRE_PRE_DEPLOY_CHECKPOINT=1`** + fresh pre-RIP **1042** |
| Migrations on prod post-deploy | **68** GST migrations **Ran** (unchanged batch) |
| Queue worker | **Restarted** (`radium-desk-queue-worker`) |
| Health `/up` | **HTTP 200** |
| Login smoke | **HTTP 200** |
| Vite manifest | **PASS** |

### Post-deploy runtime (VERIFIED)

| Check | Value |
| --- | --- |
| `release.json` | **4.1.103 / `befc58e95`**, deployed **2026-11-20 10:43:49 IST** |
| `assertNotBlocking` on prod | **Present** (grep **2** call sites) |
| GST verification | **ON** |
| B2C fallback | **OFF** |
| Receipt allowlist | **8** IDs (unchanged) |
| `radiumbox_com` auto issuance | **`allow_automatic`** (unchanged) |
| **RBP2295** / **23661** | **`statutory_invoice_id` null** |
| RBP2295 gate (read-only) | **`["Buyer GSTIN requires GST review."]`** — **no mint attempted** |

---

## Tests / CI

| Activity | Result |
| --- | --- |
| PR #384 CI (`statutory-cancellation-phpunit`, RIP) | **PASSED** (pre-merge) |
| P-20-20 focused PHPUnit (34) | **PASSED** (pre-merge local) |
| Post-deploy prod PHPUnit | **NOT RUN** |
| MariaDB concurrency | **NOT RUN** |
| Issue Invoice / mint smoke | **NOT RUN** (prohibited) |

---

## Changes summary

| | |
|--|--|
| **Preserved** | Stage A ON, fallback OFF, Stage B OFF, receipt allowlist, service auto policy, RBP2295 hold |
| **Extended** | Hardware GST gate enforcement on production |
| **Removed** | None |

---

## Explicitly NOT performed

Production invoice minting · RBP2295 issuance · GST flag change · Stage B · auto-issuance policy change · live provider/IRN/receipt · DB schema migration · moratorium activation

---

## Rollback readiness

| | |
|--|--|
| Application rollback | Redeploy **v4.1.102** / `339e3dc99` from verified RIP **`…1042…`** or backup **`20261120T050954Z`** |
| Database | **No schema delta** — code-only rollback **does not require** DB restore |
| Statutory data | **Never** roll back customer/statutory rows |

---

## Remaining risks / next Owner gate

1. **Protected GST journeys** and known-good still require separate Owner sign-off (not claimed here).
2. **RB*** service auto-mint on `radiumbox_com` unchanged — operational moratorium still optional.
3. **RBP2295** remains on **GST adjudication hold** — do not Issue Invoice.

**Production known-good: NOT declared.**