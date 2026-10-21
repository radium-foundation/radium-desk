# Radium Desk — Release Integrity Protocol (RIP)

Private, restorable **application** checkpoints for KVM production (`/var/www/radium-desk`). Checkpoints live on server disk under Laravel `storage/app/private/` — **not in Git** and not in a public web directory unless the owner provisions encrypted off-server replication separately.

Cross-project policy aligns with RadiumBox RIP (frozen naming). Desk uses project key **`radium-desk`**.

## Checkpoint lifecycle

| Phase | Requirement |
|--------|-------------|
| **Pre-deployment recovery point** | Capture + verify **before** `desk deploy` when `RIP_REQUIRE_PRE_DEPLOY_CHECKPOINT=1`. Commit suffix = **current production** `release.json` `build`. |
| **Deploy** | Existing KVM guards (clean tree, tag, changelog, rsync safety, CW overlay gates when applicable). |
| **Post-deployment checkpoint** | Capture after successful deploy + smoke; commit suffix = **deployed** release commit. |
| **`deployed`** | Capture + `sha256sum -c` + `RESTORE.md` + `php artisan rip:verify-checkpoint` |
| **`smoke-verified`** | `smoke_evidence.verified = true` in manifest (update manifest only after checks; never overwrite checkpoint dir — create new capture or approved manifest update procedure) |
| **`known-good`** | Smoke + `known_good_signoff.owner_approved` + `protected_customer_journeys` + **explicit Owner sign-off** |

A Git tag, CI pass, or deploy success **alone** is not a recovery point.

## Checkpoint ID (immutable)

```
RIP-DDMMYY-HHMM-radium-desk-<short-change>-<commit7>
```

- **Clock:** `Asia/Kolkata`, 24-hour (`DDMMYY-HHMM`, no colon).
- **Directory name** = `manifest.checkpoint_id` = ledger ID.
- Compose locally: `php artisan rip:compose-checkpoint-id --change=... --git-sha=...`

## Private storage

| Property | Value |
|----------|--------|
| Production path | `/var/www/radium-desk/storage/app/private/release-checkpoints/<checkpoint_id>/` |
| ACL target | Directory `750`, artifacts `640`, `umask 077` on capture |
| Off-server | **Not configured** in repo — design allows future encrypted adapter; do not claim replication without evidence |

Local operator staging (tests / drills): `storage/app/local-release-checkpoints/` (gitignored).

## Artifacts

| File | Purpose |
|------|---------|
| `desk-application.tar.zst` | Application source tree only (see **Archive contents** below) |
| `release.json.snapshot` | Allowlisted copy of production `storage/app/private/release.json` only (not inside the tar) |
| `SHA256SUMS` | Archive + snapshot hashes |
| `manifest.json` | Identity, excludes, DB policy, CW overlay notes, status evidence |
| `RESTORE.md` | Application-only restore steps |

### Archive contents (`desk-application.tar.zst`)

**Included (explicit allowlist only):** `app/`, `bootstrap/app.php`, `bootstrap/providers.php`, `config/`, `database/` (migrations/seeders/factories, `database/.gitignore` when present; not ad-hoc SQL dumps or `*.sqlite`), `public/`, `resources/`, `routes/`, `tools/`, `contracts/`, `docs/`, root manifests (`composer.json`, `package.json`, `artisan`, front-end config files). See `rip_capture_path_allowed_in_archive()` in `tools/lib/rip-capture-archive.sh`.

**Never archived:** `storage/`, `vendor/`, `node_modules/`, `.git/`, `.env*`, `tests/`, `.cursor/`, ad-hoc repo-root files, `bootstrap/cache/`, SQLite DB files, customer uploads, private overlays, nested `release-checkpoints/`.

Capture publishes the checkpoint directory **atomically** (staging dir + `mv` on the same filesystem). Failed captures remove staging only; existing checkpoints are never overwritten.

## Production-baseline gate (deploy)

The KVM gate (`RIP_REQUIRE_PRE_DEPLOY_CHECKPOINT=1`) verifies:

1. Checkpoint integrity on production (`rip:verify-checkpoint`).
2. Checkpoint ID suffix and manifest commit match **current** `release.json` `build` on production.

It does **not** compare the checkpoint to the Git commit you are about to deploy. Use `php artisan rip:verify-pre-deploy-gate {path} --production-build=<live build>` for the same semantics locally.

`desk deploy --dry-run` does **not** exercise the production RIP gate.

## Capture (production — separate Owner approval)

```bash
./tools/commands/capture-release-recovery-checkpoint.sh \
  --change pre-v4124 \
  --git-sha "$(ssh … 'php -r … release.json build')" \
  --status deployed
```

Dry-run ID only: `--dry-run`

The operator wrapper stages `capture-release-recovery-checkpoint-remote.sh` and `tools/lib/rip-capture-archive.sh` on the host under a temporary directory (via `scp`), runs the remote script by **absolute path** (not SSH stdin), then removes the staging directory. This keeps `BASH_SOURCE`-relative sourcing reliable under `set -euo pipefail`. Post-capture verification runs `rip:verify-checkpoint` via `ssh … bash -s --` with `LARAVEL_ROOT`, `PHP_BIN`, and `CHECKPOINT_ID` as positional arguments (not `env` + `bash -c`), so `PHP_BIN` is not lost across the SSH command line.

Verify:

```bash
./tools/commands/verify-rip-checkpoint.sh /path/to/checkpoint
# or on server:
php artisan rip:verify-checkpoint storage/app/private/release-checkpoints/<id>
```

## Deploy integration

Set on operator machine for gated releases:

```bash
export RIP_REQUIRE_PRE_DEPLOY_CHECKPOINT=1
export RIP_PRE_DEPLOY_CHECKPOINT_ID=RIP-211026-1200-radium-desk-pre-v4124-7441de0
./tools/desk deploy
```

Gate verifies checkpoint on production and matches **current** `release.json` `build` to the checkpoint ID suffix (production baseline before deploy).

Default: gate **off** (`RIP_REQUIRE_PRE_DEPLOY_CHECKPOINT` unset) for backward compatibility; **new production releases should enable it** once a valid pre-RIP exists.

## Database and Central Wallet

- **MariaDB (`radium_desk`):** Application rollback is **forward-only** for schema. Restoring tar does not undo migrations or business data. Check `database_policy.pending_migrations_at_capture` in manifest.
- **Central Wallet overlays:** Still require `capture-overlay-production-baseline.sh` and `central-wallet:verify-release-gate` per `docs/central-wallet-release-manifest-v2.md` § K.1. Full-app RIP does not replace overlay baselines.

## Preferred rollback

1. Redeploy verified semver tag via `desk deploy` (`docs/release-workflow.md`).
2. Or extract `desk-application.tar.zst` per `RESTORE.md` when tag redeploy is insufficient.

## Status updates

Do not edit captured artifacts to fake verification. For `smoke-verified` / `known-good`, follow Owner-approved manifest update policy (future automation may add `rip:record-smoke-evidence` — until then, ledger + controlled manifest amendment on a **new** checkpoint revision is preferred).
