# Central Wallet — Owner Decision Package (273 Unresolved Refunds)

**Prompt ID:** `RadiumDesk-P-30-09-31`  
**Manifest base:** `cw-migration-final-manifest-p30-09-30.json`  
**Mode:** Identity/ownership decision package only — **no financial migration**

---

## 1. Executive summary

The 292-refund Central Wallet migration population (₹165,708) remains **not execution-ready**. This package prepares Owner decisions for the **273 unresolved refunds** (₹155,055) that block migration.

| Bucket | Count | Amount (₹) |
|---|---:|---:|
| **READY_FOR_EXECUTION** | 19 | 10,653 |
| **OWNER_IDENTITY_REQUIRED** | 270 | 153,558 |
| **OWNER_RESOLUTION_REQUIRED** | 3 | 1,497 |
| **Unresolved total** | **273** | **155,055** |

**223** machine-readable Owner decision records were produced (grouped where trusted evidence links multiple refunds to one spoke user). **263** identity cases are eligible for the existing M2 identity ceremony once Owner authorizes per-case or per-customer grouping.

**EXECUTION READY = NO** — no money has been moved by this task.

---

## 2. Fixed 292-refund population

| Metric | Value |
|---|---|
| Refunds | **292** |
| Amount | **₹165,708.00** |
| Batch | `desk-refund-wallet-migration-292-p30-09-25` |
| Authoritative manifest | `storage/app/private/cw-migration-final-manifest-p30-09-30.json` |

Financial variance: **₹0** (no ledger, wallet, or refund mutations).

---

## 3. Already-ready records (19 / ₹10,653)

These refunds have trusted Desk Customer + CWID mapping and may proceed when a separate Owner financial gate authorizes migration execution.

| Refund reference | Amount | Desk customer | CWID status |
|---|---:|---|
| REF-2026-000044 | ₹597 | provisioned | EXISTING |
| REF-2026-000112 | ₹499 | provisioned | EXISTING |
| REF-2026-000117 | ₹499 | provisioned | EXISTING |
| REF-2026-000119 | ₹997 | provisioned | EXISTING |
| REF-2026-000122 | ₹499 | provisioned | EXISTING |
| REF-2026-000300 | ₹499 | User 3 (protected) | EXISTING |
| + 13 additional READY rows | — | see manifest | EXISTING |

Full list: `migration_status = READY_FOR_EXECUTION` in the P-30-09-30 manifest.

---

## 4. 270 identity cases — grouped by evidence state

| Group | Label | Count | Amount (₹) | Owner action |
|---|---|---:|---:|---|
| **A** | Existing trusted identity available | 2 | 998 | Authorize controlled CWID provisioning (no ceremony) |
| **B** | M2/identity ceremony can establish identity | 211 | 115,126 | Authorize M2 ceremony per spoke user (grouped where same user) |
| **C** | Multiple candidate identities; Owner must choose | 0 | 0 | — |
| **D** | No usable identity evidence | 52 | 34,517 | Provide trusted identity evidence or reject |
| **E** | Conflicting spoke/order identity | 5 | 2,917 | Select authoritative customer (wallet vs order) |
| **F** | Customer/CWID exists but association needs review | 0 | 0 | — |

**Group A — trusted identity, provisioning not yet authorized**

| Refund | Amount | Spoke user | Trusted evidence |
|---|---:|---|---|
| REF-2026-000271 | ₹499 | rdservice.in **549637** | Google `113563972184586422519` + verified email `jps11011@gmail.com` |
| REF-2026-000275 | ₹499 | rdservice.in **550376** | Google `105961150832664186338` + verified email `singhu364@gmail.com` |

**Group B — ceremony required (211 refunds)**

- **rdservice.in:** 209 refunds  
- **radiumbox.com:** 2 refunds  

Largest single-customer grouping: **49 refunds / ₹32,060** (decision `OWN-RadiumDesk-P-30-09-31-0213`) — same spoke user with verified credential path via M2 ceremony.

**Group D — insufficient evidence (52 refunds)**

- **rdservice.in:** 49  
- **radiumbox.com:** 2  
- **Unknown spoke:** 1  

Order email, unverified email, phone strings, or wallet ID alone — **not** sufficient for trusted identity.

**Group E — wallet/order user mismatch within OWNER_IDENTITY_REQUIRED (5 refunds)**

| Refund | Amount | Order user | Wallet user |
|---|---:|---|---|
| REF-2026-000281 | ₹617 | 549711 | 551439 |
| REF-2026-000298 | ₹717 | 549844 | 553433 |
| REF-2026-000329 | ₹617 | 138068 | 553423 |
| REF-2026-000345 | ₹917 | 433267 | 543743 |
| REF-2026-000354 | ₹49 | 550104 | 552744 |

---

## 5. Three ambiguous cases (OWNER_RESOLUTION_REQUIRED)

Circular wallet/order user mismatch across three RD* orders. **Cannot auto-resolve** without Owner selection.

### REF-2026-000263 (₹499)

| Field | Value |
|---|---|
| Order | RD3510071 |
| Order user | **548503** — PRAJWAKICCHA45@GMAIL.COM (phone 9535412576) |
| Wallet user | **548053** — tukaramgend1833@gmail.com (phone 9168990827) |
| Wallet ID | 2535 (rdin + box) |
| Conflict | `WALLET_USER_ORDER_USER_MISMATCH` |

**Interpretations (neither auto-selected):**

1. **ORDER_AUTHORITATIVE** → rdservice.in user 548503 — blocked because wallet credited to 548053  
2. **WALLET_EXECUTION_AUTHORITATIVE** → rdservice.in user 548053 — blocked because order owned by 548503  

**Evidence that would resolve:** `desk_refund_reference` on exactly one spoke wallet matching REF; Owner attestation; trusted Google/verified email for exactly one candidate.

### REF-2026-000265 (₹499)

| Field | Value |
|---|---|
| Order | RD3509417 |
| Order user | **548053** — tukaramgend1833@gmail.com |
| Wallet user | **68200** — prtalod@gmail.com |
| Wallet ID | 2534 |
| Conflict | `WALLET_USER_ORDER_USER_MISMATCH` |

Same dual interpretation pattern as 263.

### REF-2026-000266 (₹499)

| Field | Value |
|---|---|
| Order | RD3505374 |
| Order user | **68200** — prtalod@gmail.com |
| Wallet user | **548503** — PRAJWAKICCHA45@GMAIL.COM |
| Wallet ID | 2537 |
| Conflict | `WALLET_USER_ORDER_USER_MISMATCH` |

**Note:** Users 548503, 548053, and 68200 form a **circular cross-assignment** across refunds 263/265/266. Owner must resolve all three consistently.

---

## 6. Exact Owner decisions required

**Machine-readable file:** `storage/app/private/cw-owner-decisions-required-p30-09-30.json`  
(Generated by prompt P-30-09-31; also available as `cw-owner-decisions-required-p30-09-31.json`)

| Decision type | Records | Refunds covered |
|---|---:|---:|
| `TRUSTED_IDENTITY_AVAILABLE` (Group A) | 2 | 2 |
| `CEREMONY_REQUIRED` (Group B) | ~208 grouped | 211 |
| `INSUFFICIENT_EVIDENCE` (Group D) | ~52 | 52 |
| `CONFLICTING_SPOKE_ORDER_IDENTITY` (Group E) | 8 | 8 (5 identity + 3 resolution) |
| **Total decision records** | **223** | **273** |

Each record includes:

- `decision_id`, `refund_ids`, `amount`, `case_type`
- `evidence_summary`, `owner_question`, `allowed_resolution_options`
- `required_identity_evidence`
- `financial_action`: **"NONE — identity/ownership decision only"**

**No bulk approval** — individual or evidence-based customer groupings only.

---

## 7. Evidence required by decision type

| Decision type | Required evidence |
|---|---|
| Group A — trusted provisioning | Existing verified Google subject and/or verified email on spoke user; Owner authorization to run `central-wallet:migration-provision-cwid` |
| Group B — M2 ceremony | Customer completes M2 identity ceremony on correct spoke site; verified Google or verified email credential |
| Group D — insufficient | Owner-supplied trusted credential (verified Google, verified email, or completed ceremony attestation) |
| Group E / Resolution — conflict | `desk_refund_reference` on spoke wallet matching REF, **or** Owner attestation selecting order-user vs wallet-user authority |
| Resolution 263/265/266 | Consistent triplet decision across all three refunds |

**Never accepted:** order email alone, unverified email, name alone, phone string alone, wallet ID alone, fuzzy matching.

---

## 8. Identity ceremony support (M2)

| Metric | Value |
|---|---|
| Ceremony-eligible cases | **263** |
| By spoke | rdservice.in: 258, radiumbox.com: 4, unknown: 1 |
| Owner intervention required | Per decision record (authorize ceremony per user/group) |

For each eligible Group B case, the package specifies:

- **Site** (`rdservice.in` or `radiumbox.com`)
- **Local user ID** (`spoke_user_id`)
- **Credential path:** M2 ceremony → verified Google or verified email
- **Expected outcome:** Desk Customer ID + CWID provisioning via approved ceremony semantics
- **Refunds associated:** listed in grouped `refund_ids`
- **Financial action:** none until separate migration gate

Ceremony provisioning is **documented only** — not executed by this task.

---

## 9. Financial safety statement

This task performed **zero** financial operations:

| Invariant | Before | After |
|---|---|---|
| `central_wallet_ledger_entries` | 0 | **0** |
| Central wallet ledger sum | ₹0 | **₹0** |
| Spoke wallet balances | unchanged | **unchanged** |
| `refund_requests` rows/amounts/statuses | unchanged | **unchanged** |
| Financial variance | — | **₹0** |

Production verified: ledger count **0**, sum **₹0.00** (KVM8, post-package).

---

## 10. Current execution gate

```
EXECUTION READY = NO
```

Financial migration execution requires a **separate explicit Owner gate** after identity/ownership decisions in this package are resolved.

---

## Artifacts

| File | Purpose |
|---|---|
| `storage/app/private/cw-owner-decisions-required-p30-09-30.json` | 223 Owner decision records |
| `storage/app/private/cw-owner-identity-cases-p30-09-31.json` | Full 270 identity case records + 3 resolution details |
| `storage/app/private/cw-owner-identity-cases-p30-09-31.csv` | CSV export of identity cases |
| `storage/app/private/cw-owner-groups-summary-p30-09-31.json` | Group A–F counts |
| `storage/app/private/cw-owner-decision-package-p30-09-31.py` | Read-only generator (KVM8) |
| `storage/app/private/cw-migration-final-manifest-p30-09-30.json` | Authoritative 292-row manifest (unchanged) |

---

## Remaining blockers

1. **270** refunds need identity resolution (Groups A, B, D, E).
2. **3** refunds need explicit Owner conflict resolution (263, 265, 266).
3. **19** ready refunds await separate financial execution authorization.
4. No `CWID_REQUIRED` rows remain — all former 218 cases were reclassified to `OWNER_IDENTITY_REQUIRED` in P-30-09-30.
