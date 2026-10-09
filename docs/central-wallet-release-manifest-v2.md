# Central Wallet Release Manifest v2

Prompt context: Phase B release-manifest hardening.

## A. Runtime manifest schema

Schema version **2** (`manifest_contract_version` **2.0.0**). Canonical contract:
`contracts/central-wallet/v1/release-manifest-schema.json`.

Required fields:

- `project`, `target_environment`, `deployment_type`
- `contract_version` (Central Wallet API contract, currently `1.0.0`)
- `source_identity.primary_source_commit` and optional `source_components[]`
- `release_identity` (deterministic SHA-256)
- `managed_file_inventory_version`
- `managed_files[]` with `{path, sha256, exists, classification}`
- `audit.*` metadata (non-deterministic)

Legacy v1 manifests remain readable but post-deploy gates require v2 regeneration.

## B. Managed-file inventory

Explicit inventory per project:
`contracts/central-wallet/v1/managed-file-inventory.json`.

Regenerate after adding Reliability files:

```bash
php tools/generate-managed-file-inventory.php <project> <role> <release-branch>
```

Classifications:

- `tracked_release_file` — runtime PHP/JSON/YAML under version control
- `deployment_support_file` — shell deploy helpers (Desk only)
- `generated_runtime_file` — `storage/app/private/runtime-manifest.json` (never hashed into inventory)

## C. Release identity algorithm

1. Build canonical payload with stable field ordering (managed files sorted by `path`).
2. Exclude timestamps, audit metadata, and environment-mutable values.
3. `release_identity = sha256(json_encode(canonical_payload))`.

Same source checkout + same managed-file bytes ⇒ same `release_identity`.

## D. Source commit semantics

- **Git release:** `primary_source_commit` = `git rev-parse HEAD`.
- **Surgical overlay:** set `--source-commit` and optional repeated `--source-component=sha:label` for multi-commit overlays.
- Never collapse multi-commit overlays into a single misleading SHA without documenting components.

## E. Overlay semantics

Overlays rsync a declared managed-file set to production without replacing the entire application tree. The manifest records overlay prompt ID, deployment ID, and source identity so auditors can reconstruct intent.

## F. Orphan detection

`central-wallet:verify-overlay-integrity` scans `managed_scan_roots` (default: `app/CentralWallet/Reliability`) for PHP files not listed in the inventory. Orphans are reported as **FAIL**; this task does **not** delete them.

Finding categories: `missing`, `changed`, `unexpected`, `orphan`, `manifest_errors`, `release_identity_mismatch`, `source_identity_issues`.

## G. Drift detection

`central-wallet:verify-deployment-drift` delegates to overlay integrity checks when a v2 manifest is present. Legacy v1 manifests produce **WARN** instructing regeneration.

## H. Rollback verification (read-only procedure)

1. Read current manifest `audit.previous_manifest_sha256` and `audit.rollback_reference`.
2. Locate filesystem backup under `storage/app/backups/central-wallet-gate-prod-*` (production) or the declared rollback reference.
3. Verify backup manifest `release_identity` and managed-file hashes before any restoration.
4. After restoration, rerun `central-wallet:verify-overlay-integrity --json` and confirm **no orphan** findings.
5. **Do not** delete orphan files automatically; report and review.

Production rollback remains a separate owner-authorized action.

## I. Release-gate behavior

Post phase enforces:

1. Manifest schema v2 valid
2. Source identity present
3. Managed inventory complete
4. All managed hashes match disk
5. No orphan overlay files under scan roots
6. `release_identity` matches recalculated value

Pre phase defers overlay integrity when manifest is absent (**WARN**, non-blocking). Gate fails closed on post **FAIL**.

## J. Normal release procedure

1. Merge reviewed Central Wallet Reliability changes to the release branch.
2. Ensure clean worktree.
3. `php artisan central-wallet:write-runtime-manifest --deployment-type=git --require-clean-worktree`
4. Run PHPUnit Central Wallet suite + `central-wallet:verify-overlay-integrity`.
5. Deploy via normal project release process (separate authorization).
6. Post-deploy: `central-wallet:verify-release-gate --phase=post`.

## K. Surgical overlay procedure (temporary)

1. Run named-file overlay script (Desk: `tools/commands/deploy-central-wallet-prod-gate.sh`).
2. On target host: `central-wallet:write-runtime-manifest --deployment-type=overlay --overlay-prompt-id=... --source-commit=... [--source-component=sha:label ...]`
3. `central-wallet:verify-release-gate --phase=post`

## K.1 Mandatory deployment sequence (production overlay)

A checked-in Git fixture (`contracts/central-wallet/v1/fixtures/overlay-baseline`) is **not** sufficient evidence of live production compatibility when production contains overlay-only functionality (commerce config, extended `CentralWalletServiceProvider`, etc.).

**Operator steps:**

1. **Capture production baseline (read-only)** using `tools/commands/capture-overlay-production-baseline.sh` from an operator workstation with SSH access. This copies `config/central_wallet.php`, `CentralWalletServiceProvider.php`, runtime manifest, and records overlay integrity + `release_identity` without modifying production.
2. **Verify baseline identity** — read `release-identity-at-capture.txt` and `overlay-integrity-at-capture.json` in the capture directory.
3. **Prepare candidate overlay** — local checkout at the reviewed release SHA with managed-file inventory only (no `.env`, no secrets).
4. **Production → candidate compatibility (pre)** on the deploy host or CI runner with:
   - `CENTRAL_WALLET_RELEASE_GATE_OVERLAY_BASELINE_ROOT=/path/to/captured/baseline`
   - `CENTRAL_WALLET_RELEASE_GATE_OVERLAY_TARGET_ROOT=/path/to/candidate/checkout`
   - `php artisan central-wallet:verify-release-gate --phase=pre --json`  
   Must **FAIL closed** if required config paths, bindings, or capabilities are **REMOVED** or semantically **CHANGED**.
5. **Manifest integrity (pre)** — overlay integrity section must pass on candidate when manifest present locally.
6. **Customer-display synthetic (pre/post as configured)** — non-mutating BalanceReadService / Desk visibility probe when fixtures configured.
7. **Deploy** surgical overlay only (managed inventory files).
8. **Generate runtime v2 manifest** — `central-wallet:write-runtime-manifest --deployment-type=overlay ...`
9. **Post-deploy integrity** — `central-wallet:verify-overlay-integrity --json`
10. **Post-deploy dependency closure** — `central-wallet:verify-release-gate --phase=post --json` (`production_dependency_closure` section).
11. **Post-deploy customer-display synthetic** — confirm display path not `disabled` when production requires it.
12. **Financial invariants** — read-only ledger/account-link checks (no mutation).
13. **Retain rollback backup** — pre-deploy capture directory + prior runtime manifest.

## L. Migration path

1. Phase A integrated Reliability onto release branches.
2. Phase B (this document) replaces six-file tracking with full inventory + deterministic identity.
3. Future normal releases carry v2 manifests; production overlays remain valid until replaced by a normal release deploy.

**Production deployment is always a separate authorization gate.**
