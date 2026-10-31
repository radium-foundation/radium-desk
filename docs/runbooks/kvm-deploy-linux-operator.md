# KVM deploy — Linux operator host (recommended)

Use when both **deletion dry-run** and **live rsync** should run under **GNU rsync** (avoid macOS openrsync dry-run exit 23 against production).

Production app path remains **`/var/www/radium-desk`** (rsync target). This runbook does **not** deploy from that directory; it uses a **separate git checkout** on a Linux machine.

## Verified environments

| Host | GNU rsync | Git | Node/npm | SSH to KVM | Notes |
|------|-----------|-----|----------|------------|--------|
| **KVM** `ravi@187.127.129.16` | 3.2.7 | `/usr/bin/git` | via `~/.nvm` | localhost (same host) | No repo in `/var/www/radium-desk`; use `~/deploy-builds/radium-desk` |
| **GitHub Actions** (`ubuntu-latest`) | yes | yes | install in job | requires key + allowlist | No deploy workflow in repo today; add job if automating |
| **macOS** | openrsync 3.5.1 | yes | yes | yes | Deletion inventory uses **KVM GNU rsync** when `tools/lib.sh` is loaded; **live rsync still openrsync** |

## Linux procedure (KVM build checkout — recommended)

Run on **`ravi@187.127.129.16`**, not inside `/var/www/radium-desk`.

```bash
export DEPLOY_ROOT="${HOME}/deploy-builds/radium-desk"
mkdir -p "$(dirname "${DEPLOY_ROOT}")"
if [[ ! -d "${DEPLOY_ROOT}/.git" ]]; then
  git clone git@github.com:radium-foundation/radium-desk.git "${DEPLOY_ROOT}"
fi
cd "${DEPLOY_ROOT}"
git fetch origin
git checkout main
git pull --ff-only origin main
# After tooling PR merge: ensure deploy-rsync-safety fix is on main, then:
git checkout v4.1.53   # application release tag (or newer tag including tooling)

source ~/.nvm/nvm.sh
npm ci
npm run build
/usr/local/lsws/lsphp84/bin/php -n artisan release:snapshot

export DEPLOY_MODE=kvm
export RIP_REQUIRE_PRE_DEPLOY_CHECKPOINT=1
export RIP_PRE_DEPLOY_CHECKPOINT_ID='<pre-deploy-checkpoint-id>'

./tools/desk deploy --yes
```

**Credentials:** operator SSH key must reach `187.127.129.16` as `ravi` (same as macOS deploy). **Do not** run as root in `/var/www/radium-desk`.

**Separation:** `/var/www/radium-desk` = production runtime; `~/deploy-builds/radium-desk` = release tree only.

## Linux procedure (off-KVM VM / WSL)

Same steps on any Linux host with git, node, php, and SSH to `187.127.129.16`. `tools/config.sh` pins `SSH_HOST=187.127.129.16`.

## macOS fallback (after P-01-01 fix)

Allowed but **live rsync** uses openrsync. Deletion gate stages under `/tmp/desk-rsync-dryrun-*` on KVM and runs GNU rsync dry-run there.

Do **not** set `DEPLOY_RSYNC_FORCE_LOCAL_DRY_RUN=1` for production.

Optional pre-deploy check (Owner authorization required):

```bash
DEPLOY_RSYNC_PROBE_SSH=1 bash tests/scripts/deploy-rsync-vendor-protect.test.sh
```

## Rollback (application)

See `docs/rip-release-checkpoint.md` and pre-deploy RIP + DB backup from deploy gate. Application rollback target for v4.1.53 deploy: **v4.1.52 / `1f11540a`**.
