# P-20-20 — Hardware mint-gate hardening and automatic issuance protection

| Field | Value |
| --- | --- |
| Task | **P-20-20** |
| Cursor ledger | `bd0ac5bd-88f0-48e0-aacd-66eca9be9aef` |
| Desk ledger row | **RadiumDesk-P-20-20** |
| Risk | **HIGH** |
| Start (IST) | **2026-11-20 10:27:00** |
| End (IST) | **2026-11-20 10:38:00** (approx.) |
| Duration | **~11 min** (verify + implement + tests + git) |

## Verdict

**PASSED** — authoritative `CommerceBuyerGstMintGate` enforced on all hardware statutory mint entry points; permanent regressions **34/34 PASS** in focused bundle. **NO deploy / NO prod mutation.**

---

## P-20-19 gap (VERIFIED)

| Finding | Class |
| --- | --- |
| `issueFromCommerceOrder` hardware branch skipped `assertOrderCanMint` | **VERIFIED** |
| `HardwareFulfilmentInvoiceService::issueInvoice` called `mint()` directly (post-serial auto path) | **VERIFIED** |
| `mint()` already ran `assertB2bInputReadinessForMint` for valid B2B GSTIN | **VERIFIED** |
| Gate skipped when `requiresProviderVerification` false (aligned billing/GSTIN) | **VERIFIED** |
| Defence-in-depth required at service boundary before mint | **Owner / P-20-20** |

---

## Changes

| Class | Item |
| --- | --- |
| **Extended** | `CommerceBuyerGstMintGate::assertNotBlocking()` — throws `ValidationException` with `eligibility` errors |
| **Changed** | `StatutoryInvoiceService::issueFromCommerceOrder` — `assertNotBlocking` before hardware `issue()` |
| **Changed** | `HardwareFulfilmentInvoiceService::issueInvoice` — `assertNotBlocking` before serial/mint work inside transaction |
| **Extended** | `HardwareCommerceBuyerGstMintGateTest` (10 cases, `permanent_regression`) |
| **Preserved** | Service path `assertOrderCanMint`; auto-issuance policy; B2C native/missing GSTIN; fallback OFF behaviour |
| **Removed** | None |
| **Docs** | P-20-19 artifact + ledger row committed in same PR |

---

## Mint path findings

### Manual (Finance Hub / hardware UI)

`StatutoryInvoiceIssueController::issue` → `issueFromCommerceOrder` → **`commerceBuyerGstMintGate::assertNotBlocking`** → `HardwareCommerceStatutoryInvoiceGuard::issue` → `issueInvoice` → **`assertNotBlocking` again** → `mint()`.

### Automatic post-serial

`HardwareSerialAllocationService` → `HardwareStatutoryInvoiceIssuer::issueAfterSerialsAllocated` → `issueInvoice` → **`assertNotBlocking`** → `mint()`.

### Policy

No change to `service_statutory_invoice.auto_issuance` or `radiumbox_com` channel defaults.

---

## Tests (focused bundle)

| Suite | Result |
| --- | --- |
| `HardwareCommerceBuyerGstMintGateTest` | **10/10 PASS** |
| `CommerceOrderBuyerGstVerificationTest` | **PASS** (in bundle) |
| `FinanceHubHardwareSerialGateTest` | **PASS** (in bundle) |
| `CommerceOrderMissingBuyerGstinNativeB2cTest` | **PASS** (in bundle) |
| **Bundle total** | **34/34 PASS**, **151** assertions, **~30s** |
| Full PHPUnit suite | **NOT RUN** (per task) |
| MariaDB concurrency | **NOT RUN** (no harness invoked; unchanged policy) |

---

## Production evidence (read-only, unchanged)

| Item | Value |
| --- | --- |
| Release | **v4.1.102** / **`339e3dc99`** |
| RBP2295 / **23661** | `statutory_invoice_id` **null**, invoice count **0** |
| GST verification | **ON**; B2C fallback **OFF** |

---

## Explicitly NOT performed

| Activity | Status |
| --- | --- |
| Production deploy | **NO** |
| Production mint / Issue Invoice | **NO** |
| Flag / policy / worker / scheduler changes | **NO** |
| Live provider / IRN / receipt publication | **NO** |
| Mint moratorium activation | **NO** |

---

## Remaining risks

| Risk | Class |
| --- | --- |
| **RB*** service auto-mint on `radiumbox_com` unchanged until deploy + optional moratorium | **INFERRED** |
| Hardware gate only applies when provider verification policy applies (state mismatch) | **VERIFIED** design |
| Prod fix requires **separate Owner deploy** authorization | **Gate** |

---

## Next Owner approval

1. **Deploy** merge SHA to production (after CI green).
2. **RBP2295** — remain on hold until GST adjudication (unchanged).
3. Optional **service auto-issuance moratorium** during GST observability — separate instruction (P-20-19).

**Production known-good: not claimed.**