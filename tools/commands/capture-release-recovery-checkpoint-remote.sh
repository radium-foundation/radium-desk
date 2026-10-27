#!/usr/bin/env bash
# Runs on KVM Desk host via SSH. Publishes storage/app/private/release-checkpoints/<id>/ atomically.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ARCHIVE_LIB="${SCRIPT_DIR}/../lib/rip-capture-archive.sh"
if [[ ! -f "${ARCHIVE_LIB}" ]]; then
    echo "rip-capture: missing archive helper at ${ARCHIVE_LIB}" >&2
    exit 1
fi
# shellcheck source=tools/lib/rip-capture-archive.sh
source "${ARCHIVE_LIB}"

CHECKPOINT_ID="$1"
CHANGE="$2"
SHORT_SHA="$3"
FULL_SHA="$4"
STATUS="$5"
LARAVEL_ROOT="$6"
PHP_BIN="$7"

BASE="${LARAVEL_ROOT}/storage/app/private/release-checkpoints"
DEST="${BASE}/${CHECKPOINT_ID}"
STAGING_DIR=""

cleanup_staging() {
    if [[ -n "${STAGING_DIR}" && -d "${STAGING_DIR}" ]]; then
        rm -rf "${STAGING_DIR}"
    fi
}

trap cleanup_staging EXIT INT TERM

if [[ -e "${DEST}" ]]; then
    echo "checkpoint already exists: ${DEST}" >&2
    exit 2
fi

umask 077
mkdir -p "${BASE}"
chmod 750 "${BASE}"

if ! rip_capture_check_disk_space "${BASE}"; then
    exit 1
fi

STAGING_DIR="$(mktemp -d "${BASE}/.rip-staging.${CHECKPOINT_ID}.XXXXXX")"
chmod 750 "${STAGING_DIR}"

ARCHIVE="${STAGING_DIR}/desk-application.tar.zst"

echo "==> Desk application archive (application tree only; no storage/app/private in tar)"
if ! rip_capture_build_application_archive "${LARAVEL_ROOT}" "${ARCHIVE}"; then
    echo "capture failed: application archive" >&2
    exit 1
fi

if ! rip_capture_assert_archive_policy "${ARCHIVE}"; then
    echo "capture failed: archive policy check" >&2
    exit 1
fi

rip_capture_copy_release_json_snapshot "${LARAVEL_ROOT}" "${STAGING_DIR}"

VITE_SHA=""
if [[ -f "${LARAVEL_ROOT}/public/build/manifest.json" ]]; then
    VITE_SHA="$(shasum -a 256 "${LARAVEL_ROOT}/public/build/manifest.json" | awk '{print $1}')"
fi

(
    cd "${STAGING_DIR}"
    files=(desk-application.tar.zst)
    if [[ -f release.json.snapshot ]]; then
        files+=(release.json.snapshot)
    fi
    if ! sha256sum "${files[@]}" > SHA256SUMS; then
        echo "capture failed: SHA256SUMS generation" >&2
        exit 1
    fi
    if ! sha256sum -c SHA256SUMS; then
        echo "capture failed: SHA256SUMS verification" >&2
        exit 1
    fi
)

PENDING_MIG="$("${PHP_BIN}" "${LARAVEL_ROOT}/artisan" migrate:status --no-ansi 2>/dev/null | awk '/Pending/ {print $1}' | paste -sd, - || true)"
PHP_V="$("${PHP_BIN}" -v | head -1 | awk '{print $2}')"
ARCHIVE_BYTES="$(wc -c < "${ARCHIVE}" | tr -d ' ')"
CAPTURED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

export RIP_MANIFEST_DIR="${STAGING_DIR}" CHECKPOINT_ID CHANGE SHORT_SHA FULL_SHA STATUS LARAVEL_ROOT
export ARCHIVE_BYTES CAPTURED_AT VITE_SHA PENDING_MIG PHP_V

if ! "${PHP_BIN}" -r '
$manifest = [
  "schema_version" => 1,
  "checkpoint_id" => getenv("CHECKPOINT_ID"),
  "status" => getenv("STATUS"),
  "captured_at_utc" => getenv("CAPTURED_AT"),
  "project_key" => "radium-desk",
  "change_label" => getenv("CHANGE"),
  "capture_mode" => "production-tree",
  "source_identity" => [
    "git_remote" => "github.com/radium-foundation/radium-desk",
    "git_branch" => "main",
    "git_commit_short" => getenv("SHORT_SHA"),
    "git_commit_full" => getenv("FULL_SHA"),
  ],
  "application_archive" => [
    "filename" => "desk-application.tar.zst",
    "bytes" => (int) getenv("ARCHIVE_BYTES"),
    "includes" => [
      "app/",
      "bootstrap/",
      "config/",
      "database/migrations/",
      "public/",
      "resources/",
      "routes/",
      "tools/",
      "composer.json",
      "composer.lock",
      "package.json",
      "artisan",
    ],
    "excludes_entire_trees" => [
      "storage/",
      "vendor/",
      "node_modules/",
      ".git/",
      ".env*",
    ],
  ],
  "build_identity" => [
    "vite_manifest_sha256" => getenv("VITE_SHA") ?: null,
    "release_json_snapshot" => is_file(getenv("RIP_MANIFEST_DIR") . "/release.json.snapshot") ? "release.json.snapshot" : null,
  ],
  "excludes" => [
    ".env and .env.*",
    "entire storage/ tree (runtime, logs, uploads, private overlays)",
    "storage/app/private/* except release.json copied only as release.json.snapshot",
    "vendor/",
    "node_modules/",
    "database/*.sqlite",
    "database dumps",
    "customer data",
    "storage/app/private/release-checkpoints/",
  ],
  "database_policy" => [
    "rollback_scope" => "application_code_only",
    "forward_only_migrations" => true,
    "pending_migrations_at_capture" => getenv("PENDING_MIG") ?: "",
    "note" => "Restoring application files does not roll back MariaDB schema or business data.",
  ],
  "central_wallet_overlay" => [
    "required_for_cw_overlay_deploys" => true,
    "baseline_capture_script" => "tools/commands/capture-overlay-production-baseline.sh",
    "release_gate" => "php artisan central-wallet:verify-release-gate",
    "note" => "Full-app checkpoint does not replace CW overlay baselines or runtime-manifest verification.",
  ],
  "integrity" => [
    "sha256sums_file" => "SHA256SUMS",
    "verified_at_capture" => true,
  ],
  "storage" => [
    "class" => "kvm8_private_disk",
    "path" => "storage/app/private/release-checkpoints/" . getenv("CHECKPOINT_ID"),
    "permissions" => "750 directory, 640 artifacts",
    "off_server_replication" => "not_configured",
    "publish_mode" => "rename_staging_directory_on_same_filesystem",
  ],
  "runtime_versions" => ["php" => getenv("PHP_V")],
  "smoke_evidence" => null,
  "known_good_signoff" => null,
];
$written = file_put_contents(
  getenv("RIP_MANIFEST_DIR") . "/manifest.json",
  json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
);
if ($written === false) {
  fwrite(STDERR, "capture failed: manifest.json write\n");
  exit(1);
}
'; then
    echo "capture failed: manifest.json" >&2
    exit 1
fi

cat > "${STAGING_DIR}/RESTORE.md" <<RESTORE
# Radium Desk RIP restore — ${CHECKPOINT_ID}

**Application code only.** This checkpoint does **not** include MariaDB data, \`.env\`, \`storage/\` runtime files, private overlays, or user uploads.

## Prerequisites

- Production path: \`${LARAVEL_ROOT}\`
- Preserve live \`.env\`, \`storage/\`, and database (\`radium_desk\`)
- For Central Wallet overlay releases, also retain overlay backups and run CW release gates per \`docs/central-wallet-release-manifest-v2.md\`

## Extract application archive

\`\`\`bash
cd ${LARAVEL_ROOT}
zstd -d -c desk-application.tar.zst | tar -xf -
\`\`\`

Copy the archive from the checkpoint directory to the server first.

## Post-extract (production PHP)

\`\`\`bash
composer install --no-dev --optimize-autoloader
php artisan migrate:status
php artisan optimize:clear && php artisan optimize
\`\`\`

**Do not** run \`migrate:rollback\` as part of application rollback. Compare \`pending_migrations_at_capture\` in manifest.json with live \`migrate:status\`.

## Preferred rollback when Git tag is available

Redeploy the verified semver tag with \`./tools/desk deploy\` from an operator machine (matches \`docs/release-workflow.md\`).

## Smoke

Re-run documented Desk smoke checks before updating checkpoint status to \`smoke-verified\` or \`known-good\`.
RESTORE

chmod 640 "${STAGING_DIR}/manifest.json" "${STAGING_DIR}/SHA256SUMS" "${STAGING_DIR}/desk-application.tar.zst" "${STAGING_DIR}/RESTORE.md"

if [[ -e "${DEST}" ]]; then
    echo "checkpoint appeared during capture: ${DEST}" >&2
    exit 2
fi

trap - EXIT INT TERM
mv "${STAGING_DIR}" "${DEST}"
STAGING_DIR=""

echo "checkpoint_ready=${DEST}"
echo "checkpoint_id=${CHECKPOINT_ID}"
