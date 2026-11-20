# P-20-48 — Deploy v4.1.107 and Shiprocket reassignment recovery

| Field | Value |
|---|---|
| Task | **P-20-48** |
| Cursor ledger | `bd0ac5bd-88f0-48e0-aacd-66eca9be9aef` |
| Risk | **HIGH** |
| Session (IST) | **~22:18–22:30** (2026-11-20) |
| Duration (approx.) | Deploy ~**44s** wall; backup ~**119s**; reassignment attempts ~**5s** each |

---

## Executive summary

| Phase | Result |
|---|---|
| **Deploy v4.1.107** | **PASSED** |
| **Controlled courier reassignment** | **FAILED** (both shipments — provider **rejected** reassignment to courier **252**) |
| **Recovery / pickup-error clearance** | **NOT VERIFIED** — do not treat as known-good |

---

## Step 1 — VERIFY (pre-deploy)

| Item | Evidence |
|---|---|
| PR **#394** merged | **VERIFIED** (`12feb069f` squash of P-20-47) |
| Production baseline | **v4.1.106** / **`e36d078c7`** — **VERIFIED** (`release.json` pre-deploy) |
| **RBP2310** | HF **1540**, Shipment **590**, ext **1735816935**, AWB **SF4531928841SPF**, courier **252** |
| **RBP2281** | HF **1530**, Shipment **579**, ext **1735660074**, AWB **SF4531928884SPF**, courier **252** |
| Pre-reassign track | Both **status 13**, `pickupErrorOnTrack` **yes** (GET before deploy session) |

**Release commit note:** Deployed application build is **`2881c73d9`** (tag **`v4.1.107`**) = P-20-47 merge **`12feb069f`** plus **CHANGELOG 4.1.107** entry required by deploy gate. Functional delta matches PR **#394**.

---

## Step 2 — Backup and PRE-RIP

### Backup **`20261120T164849Z`**

| Field | Value |
|---|---|
| Phase | **cloud_uploaded** |
| App manifest | **v4.1.106** / **`e36d078c7`** |
| DB SHA-256 | `ed5acf6585370794a3c709c4d35df830f7162bcf532ea442fadfb8cc173ebc66` |
| Size | **353,923,682** bytes (`database.sql.gz.gpg`) |

### Disposable restore drill

| Item | Status |
|---|---|
| Full disposable DB restore drill for this backup | **NOT RUN** (same class as P-20-44 optional drill; fresh backup SHA **verified** on-host) |

### Hostinger snapshot

| Item | Value |
|---|---|
| Initial gate | **BLOCKED** — snapshot **459755** expired (`2026-11-20T17:08:04Z`) |
| Owner-authorized refresh | **POST** snapshot — **success**, `snapshot_created_at` **2026-11-20T16:53:47Z**, expires **2026-11-21T16:53:47Z** |
| Action id | **126816906** |

### PRE-RIP

| Checkpoint | Verify |
|---|---|
| `RIP-201126-2221-radium-desk-pre-p20-48-v4107-e36d078` | **PASS** (`checksum_ok=true`, matches prod **`e36d078c7`**) |

---

## Step 3 — DEPLOY

| Item | Value |
|---|---|
| Command | `./tools/desk deploy --yes` |
| Env | `DEPLOY_RUN_MIGRATIONS=0`, `RIP_REQUIRE_PRE_DEPLOY_CHECKPOINT=1`, `HOSTINGER_SNAPSHOT_GATE=1`, `DEPLOY_TIER=A` |
| Worktree | `.worktrees/p20-48-deploy` @ **`release/p20-48-v4107`** |
| Log | `.artifacts/P-20-48-deploy.log` |
| Migrations | **SKIPPED** |
| Prod `release.json` (post) | **4.1.107** / **`2881c73d9`**, deployed **`2026-11-20T22:24:04+05:30`** |
| Artisan command | `desk:shiprocket-provider-courier-reassign` **present** on prod |

---

## Step 4 — POST-DEPLOY

| Check | Result |
|---|---|
| POST-RIP | `RIP-201126-2224-radium-desk-post-p20-48-v4107-2881c73` — **PASS** |
| `/up` | HTTP **200** |
| `/login` | HTTP **200** |
| Full PHPUnit | **NOT RUN** |

---

## Step 5 — RBP2310 (HF 1540)

### Dry-run

```json
{
  "fulfilment_id": 1540,
  "shipment_id": 590,
  "external_shipment_id": "1735816935",
  "previous_awb": "SF4531928841SPF",
  "preflight_pickup_error": true,
  "wallet": { "balance_amount": "3799.26" }
}
```

### Execute (single POST)

**API:** `POST /courier/assign/awb` with `status: reassign`, `shipment_id=1735816935`, `courier_id=252`

**Outcome:** **REJECTED** (non-retryable)

- Provider message: **`Selected courier not available between 110020 and 532407`**
- Desk event: `provider_courier_reassign_post` → **`outcome: rejected`**

### Post-GET verification

| Field | Value |
|---|---|
| AWB | **Unchanged** — `SF4531928841SPF` |
| Ext shipment ID | **1735816935** |
| Courier (Desk) | **252** |
| `trackByAwb` status | **`0`** (no activity label; **not** status **13** in this response shape) |
| `pickupErrorOnTrack` | **no** (on raw GET immediately after POST) |
| Desk `provider_track_*` | **null** (not synced on rejection path) |
| Duplicate POST | **Blocked** by idempotency (event recorded) |

**Reassignment decision:** **FAILED**

**Recovery claim:** **BLOCKED** — provider rejected reassign; no new AWB; label/pickup not run; pickup-error clearance **not** established with full track evidence.

---

## Step 6 — RBP2281 (HF 1530)

### Dry-run

- `preflight_pickup_error`: **true**
- Wallet: **3882.86** (between attempts)

### Execute (single POST)

**Outcome:** **REJECTED**

- Provider message: **`Selected courier not available between 110020 and 811211`**
- Event: **`outcome: rejected`**

### Post-GET verification

| Field | Value |
|---|---|
| AWB | **Unchanged** — `SF4531928884SPF` |
| Ext shipment ID | **1735660074** |
| Courier | **252** |
| `trackByAwb` status | **`0`** |
| `pickupErrorOnTrack` | **no** (same caveat as RBP2310) |
| Desk `provider_track_*` | **null** |

**Reassignment decision:** **FAILED**

---

## Step 7 — Wallet / billing

| When | Balance (`getWalletBalance`) |
|---|---|
| Pre-deploy / RBP2310 dry-run | **3799.26** |
| After RBP2310 POST rejected | **3882.86** |
| After RBP2281 POST rejected | **3966.46** |

**Interpretation:** Wallet **increased** across the window without successful AWB assign responses in captured JSON. **No** second AWB charge evidenced; delta **not** reconciled to a specific Shiprocket line item in this task → billing impact **UNKNOWN** (not a reassignment debit).

---

## Per-shipment summary

| Shipment | Reassign | AWB | Provider POST | Pickup error cleared |
|---|---|---|---|---|
| **RBP2310** | **FAILED** | Retained | 1× rejected | **UNKNOWN** / **NOT VERIFIED** |
| **RBP2281** | **FAILED** | Retained | 1× rejected | **UNKNOWN** / **NOT VERIFIED** |

---

## NOT RUN (per scope)

- Label generation  
- Pickup request  
- Second POST / blind retry  
- Cancellation / rebook  
- Full PHPUnit suite  
- Disposable restore drill (noted above)

---

## Rollback

| Target | Action |
|---|---|
| Application | Redeploy **`v4.1.106`** / **`e36d078c7`** (or restore from POST-RIP **`2881c73d9`**) |
| Database | Restore from **`20261120T164849Z`** if needed (pre-4.1.107) |
| Provider | Reassign POSTs **rejected** — no Desk AWB change; provider state requires Shiprocket support / alternate courier strategy |

---

## CI / merge (reference)

| Item | Status |
|---|---|
| PR **#394** | **MERGED** |
| RIP checks at merge | **PASS** |

---

## Remaining risks

1. **Courier 252** not serviceable for **reassign** on these lane/postcode pairs despite prior assignment.  
2. Desk **provider_track_*` still null** — UI may still allow label/pickup without persisted error state.  
3. **One POST recorded per shipment** — further API reassign blocked until GET-based clearance + Owner plan (alternate `courier_id` or provider support).  
4. **Recovery not claimed** until verified track status, AWB lifecycle, and label eligibility align.