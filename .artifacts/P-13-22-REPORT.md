# P-13-22 — Legacy RDP payload hash replay (matrix row 213)

| Field | Value |
| --- | --- |
| Global Prompt ID | **P-13-22** |
| Cursor ledger ID | **RadiumDesk-P-13-22** |
| Repository | `/Users/ravi/RadiumWebsites/radium-desk` |
| Remote | `git@github.com:radium-foundation/radium-desk.git` |
| Worktree | `.worktrees/p13-22-channel-hash` → `feature/p13-22-legacy-rdp-hash` |
| Verified `origin/main` (start) | **`670c24239eae4e5a7d32a9998a248392ffd89e5e`** |
| Wallet WIP (unchanged) | **`d94816153cd2c89f8175d29a1510b38b4c16df6b`** (`feature/p18-33-unified-wallet-display`) |
| Start | **2026-11-13 11:52:00 IST** |
| End | **2026-11-13 12:05:00 IST** (approx.) |

---

## Exact queue assignment

| Field | Value |
| --- | --- |
| Line | **213** |
| Method | `Tests\Unit\ChannelIngest\ChannelIngestPayloadHasherTest::test_legacy_rdp_service_hash_matches_pre_v4_0_95_stored_hash_on_replay` |
| Focused regression | Full `ChannelIngestPayloadHasherTest` |
| Dependencies | P-13-08 statutory ingest — read-only; **not modified** |

---

## Root cause

| Classification | Detail | Label |
| --- | --- | --- |
| **Serialization / canonicalization incompatibility** | `legacyPreHardwareRdpServiceHash()` reused `serviceCanonical()` including **composer-produced** flat `billing_address` / `shipping_address` (`StatutoryBillingAddressComposer` via P-03-43 ingest path). Pre-v4.0.95 stored hashes used **comma-joined structured parts** (legacy `ChannelIngestPayloadValidator::address()` semantics). Example: composer flat `Pipursai, Pipursai, Kharagpur, West Bengal 721445` vs legacy `…, West Bengal, 721445` → hash `1cada41a…` vs expected `289c41b2…`. | **VERIFIED** |

Not application hardware/service hash algorithm drift on current `hash()` path (**VERIFIED** unchanged for new ingests).

---

## Sequential steps

| Step | Action | Result |
| ---: | --- | --- |
| 1 | Verify repo, claim **RadiumDesk-P-13-22**, confirm row 213 failing on `670c2423` | **PASS** |
| 2 | Trace hasher + validator; compare legacy vs composer flats | **PASS** (repro script) |
| 3 | Implement `legacyPreV4095ServiceCanonical` address join in hasher only | **PASS** |
| 4 | Assigned method + class + neighbour RIN ingest legacy replay | **PASS** |
| 5 | Ledger, commit, PR, CI, merge | See below |

---

## Behaviour

| | |
| --- | --- |
| **Preserved** | `hash()`, `serviceCanonical()`, `hardwareCanonical()`, `matchesStored()` current-hash path |
| **Extended** | Legacy replay hash uses pre-composer flat address reconstruction from structured snapshots |
| **Changed** | `legacyPreHardwareRdpServiceHash()` canonical inputs only |
| **Removed** | Nothing |

---

## Focused validation

Worktree: `.worktrees/p13-22-channel-hash`  
PHP: `DESK_PHP_BIN=/opt/homebrew/bin/php ./bin/phpunit-isolated`

| Command | Exit | Result | Duration |
| --- | ---: | --- | --- |
| `--filter test_legacy_rdp_service_hash_matches_pre_v4_0_95_stored_hash_on_replay` | 0 | **1/1 PASS**, 3 assertions | **200 ms** |
| `tests/Unit/ChannelIngest/ChannelIngestPayloadHasherTest.php` | 0 | **13/13 PASS**, 32 assertions | **576 ms** |
| `--filter test_legacy_rdp_stored_service_hash_replay_is_duplicate_and_opens_fulfilment` (`HardwareRinIngestTest`) | 0 | **1/1 PASS**, 11 assertions | **1799 ms** |

**Full PHPUnit suite:** **NOT RUN** (Owner approval required).

---

## Diff review

- **Single app file:** `app/Services/ChannelIngest/ChannelIngestPayloadHasher.php` — legacy canonical helper only.
- **No** `ChannelIngestPayloadValidator`, statutory issuance, or P-13-08 cluster edits.
- **Ledger:** `docs/cursor-prompt-ledger.md` append **RadiumDesk-P-13-22**.

---

## Git / PR

| Field | Value |
| --- | --- |
| Branch | `feature/p13-22-legacy-rdp-hash` |
| Starting HEAD | `670c24239eae4e5a7d32a9998a248392ffd89e5e` |
| Commit / merge SHA | *(filled after PR merge)* |
| PR | *(filled after create)* |
| CI | *(filled after checks)* |

**Register / CSV:** not edited.

**Production:** **NO** — not performed.

---

## Remaining risks

- Legacy replay hashes that lack structured address snapshots rely on stored flat strings; behaviour unchanged from prior `legacyPreHardwareRdpServiceHash` fallback path (**INFERRED** low incidence for RDP replay fixtures).
- Matrix row 213 register status still **STILL FAILING** until separate CSV reconciliation (**VERIFIED** policy).
