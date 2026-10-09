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
6. Post-deploy: `central-wallet:verify-release-gate --phase=post` **with the retained pre-deploy baseline** (see § K.1).

## K. Surgical overlay procedure (temporary)

1. Run named-file overlay script (Desk: `tools/commands/deploy-central-wallet-prod-gate.sh` or `tools/commands/deploy-cw-manifest-v2-overlay.sh` per project).
2. On target host: `central-wallet:write-runtime-manifest --deployment-type=overlay --overlay-prompt-id=... --source-commit=... [--source-component=sha:label ...]`
3. Post-deploy gates per **§ K.1** (baseline env required for overlay compatibility / dependency closure).

## K.1 Mandatory deployment sequence (production overlay)

A checked-in Git fixture (`contracts/central-wallet/v1/fixtures/overlay-baseline`) is **not** a substitute for a **captured live production baseline**. Git fixtures are for CI/regression only. Production hosts typically **do not** ship that directory; overlay compatibility and production dependency closure need a **retained capture or pre-deploy backup** at post-gate time.

Managed overlays also write `storage/app/backups/cw-manifest-v2-pre-<timestamp>/` on the target host and record `audit.rollback_reference` (or equivalent) on the runtime v2 manifest — that backup is the **pre-overlay production tree** for managed files and may be used as the post-gate baseline when it contains the same artifacts as capture (config, provider, contracts).

### A. Before deployment — capture live production baseline

1. **Capture (read-only)** from an operator machine with SSH access:

   ```bash
   ./tools/commands/capture-overlay-production-baseline.sh \
     <project-key> <ssh_host> <remote_prod_path> [local_output_dir]
   ```

   Example (Desk):

   ```bash
   ./tools/commands/capture-overlay-production-baseline.sh \
     radium-desk 187.127.129.16 /var/www/radium-desk \
     ./storage/app/local-baselines/radium-desk-pre-deploy
   ```

2. **Retain** the entire output directory for the deployment report.
3. **Record** in the deployment report:
   - absolute path to the capture directory;
   - `BASELINE-META.txt`, `release-identity-at-capture.txt`, `overlay-integrity-at-capture.json`;
   - prior production `release_identity` before overlay.
4. **Do not** delete the capture until post-gate and financial checks complete.

### B. Pre-gate — captured live baseline → candidate release

On the deploy host or CI runner (candidate = reviewed release checkout or staged tree):

```bash
export CENTRAL_WALLET_RELEASE_GATE_OVERLAY_BASELINE_ROOT=/absolute/path/to/captured-live-baseline
export CENTRAL_WALLET_RELEASE_GATE_OVERLAY_TARGET_ROOT=/absolute/path/to/candidate-checkout   # optional; defaults to project root when running artisan in candidate tree
php artisan central-wallet:verify-release-gate --phase=pre --json
```

- **Require `final=PASS`** (and no **FAIL** in any section) before deployment.
- Comparison semantics: **captured live production baseline → candidate overlay**.
- **FAIL** on removed/changed production-required dependencies is a **release blocker**. Do not deploy.

Optional: upload capture and candidate to the production host under `/tmp/cw-baseline-*` and `/tmp/cw-candidate-*` and run `artisan` from the live app root with the env vars above (same semantics as local pre-gate).

### C. Deploy — managed-file overlay

1. Create/verify **pre-overlay backup** on the target host (overlay scripts copy managed files into `storage/app/backups/cw-manifest-v2-pre-<timestamp>/`).
2. Deploy **only** managed inventory files from the approved release SHA (no `.env`, secrets, customer data, logs, or unrelated app changes).
3. `php artisan optimize:clear` (or project-standard cache clear) on the host.
4. **Generate runtime v2 manifest** on the host:

   ```bash
   php artisan central-wallet:write-runtime-manifest \
     --deployment-type=overlay \
     --project=<project-key> \
     --environment=production \
     --overlay-prompt-id=<prompt-id> \
     --source-commit=<git-sha> \
     --rollback-reference=<backup-path> \
     --git-sha=<git-sha> \
     --git-branch=<branch>
   ```

5. Record backup path and new `release_identity` in the deployment report.

### D. Post-gate — reuse the same retained baseline

**Do not** run post-gate for overlay compatibility / production dependency closure with the baseline **unset**. That produces **WARN** (`overlay_compatibility_baseline`, `overlay_baseline_unconfigured`) — an **evidence/configuration gap**, not proof of compatibility.

Reuse **the same** directory as pre-gate:

- **Preferred:** the **captured live baseline** from § A (copy retained on operator machine or synced read-only to the host), **or**
- **Equivalent:** the **pre-deploy overlay backup** on the host (`storage/app/backups/cw-manifest-v2-pre-<timestamp>/`) when it contains the pre-overlay production config/provider/contracts needed for comparison.

On the **deployed** production app root:

```bash
export CENTRAL_WALLET_RELEASE_GATE_OVERLAY_BASELINE_ROOT=/absolute/path/to/retained-pre-deploy-baseline
# TARGET defaults to current production tree (base_path) when artisan runs on the host
php artisan central-wallet:verify-release-gate --phase=post --json
php artisan central-wallet:verify-overlay-integrity --json
```

- For **overlay compatibility** and **production_dependency_closure**, expect **PASS** when the baseline env points at the retained capture/backup and the overlay preserved required production behavior.
- **`final=WARN`** with only baseline-unconfigured messages means the operator skipped the env — **fix the procedure**, not the gate.
- Any section **`FAIL`** is still a **release blocker** (rollback per § K.1.I). Do not weaken FAIL interpretation.

Then run **customer-display** validation on **consumer spokes** (see § F). Run **financial invariants** (§ G).

### E. Post-gate interpretation (WARN vs FAIL)

| Symptom | Meaning | Action |
|--------|---------|--------|
| `overlay_compatibility` / `production_dependency_closure` **WARN**, details *baseline not configured* | Post-gate ran **without** `CENTRAL_WALLET_RELEASE_GATE_OVERLAY_BASELINE_ROOT` (and no valid fixture on host) | Re-run post-gate with retained capture or pre-deploy backup path |
| Same sections **FAIL** | Semantic dependency removed/changed vs baseline | **Stop.** Rollback. Do not report deploy success |
| `overlay_integrity` **FAIL** | Manifest/hash/orphan mismatch on deployed tree | **Stop.** Rollback |
| Aggregate **`final=WARN`** with only provider `customer_display_provider_lane` on Desk | Expected for **provider** role (§ F) | Document in report; do not treat as compatibility failure if oc/pdc/integrity passed |

### F. Radium Desk (provider) vs consumer spokes

- **Radium Desk (`role=provider`):** the release-gate **customer-display synthetic is intentionally N/A** on the provider lane. It reports **WARN** (`customer_display_provider_lane` — “applies to spoke consumers”). **Do not** suppress or reinterpret that WARN in tooling; document it in the deployment report.
- **rdservice.in, radiumbox.com, rdservice.net (consumers):** **customer-display synthetic is required** post-deploy when configured (`central_wallet.release_gate.customer_display.local_user_id` / synthetic probe settings). Confirm display path is not erroneously `disabled` when production requires wallet UI.

### G. Financial safety (read-only, all projects)

After post-gate, verify **without mutation**:

- **Radium Desk:** RD10575 — ledger **#48** remains **₹499.00** `posted`, reserved **0**, expected single ledger row for that wallet; no duplicate credit; no new wallet transactions from the overlay.
- **All projects:** no unexpected wallet, account-link, order, or payment mutation attributable to the overlay.
- **Do not** report deployment success if required validation **FAIL**ed or mandatory checks were skipped.

### H. Operator checklist

- [ ] Capture live production baseline (`capture-overlay-production-baseline.sh`); record path + identities
- [ ] Pre-gate **PASS** with `CENTRAL_WALLET_RELEASE_GATE_OVERLAY_BASELINE_ROOT=<capture>` → candidate
- [ ] Pre-overlay **backup** on host verified readable
- [ ] Deploy managed overlay + write runtime manifest v2
- [ ] Post-gate with **same** retained baseline (capture or `cw-manifest-v2-pre-*` backup); require oc/pdc **PASS** (not baseline-unconfigured **WARN**)
- [ ] `central-wallet:verify-overlay-integrity` **PASS**
- [ ] Consumer **customer-display** checks (spokes); note Desk provider WARN if applicable
- [ ] Financial invariants (RD10575 on Desk; link/order/payment spot checks)
- [ ] Health / auth / site-isolation smoke as per project runbook
- [ ] Final release report (baseline path, backup path, before/after `release_identity`, gate JSON summaries)
- [ ] **Rollback** if any genuine release-blocking **FAIL** (restore backup tree + prior manifest; re-verify)

### I. Rollback

If a mandatory gate **FAIL**s: stop further projects, restore the recorded pre-overlay backup, restore prior runtime manifest if needed, re-run health and wallet/display checks, document failure. Do not improvise production fixes without a new authorized release.

## L. Migration path

1. Phase A integrated Reliability onto release branches.
2. Phase B (this document) replaces six-file tracking with full inventory + deterministic identity.
3. Future normal releases carry v2 manifests; production overlays remain valid until replaced by a normal release deploy.

**Production deployment is always a separate authorization gate.**
