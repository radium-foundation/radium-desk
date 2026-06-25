# Desk Deployment Toolkit

Production deployment utilities for the Radium Service Desk Laravel application.

All commands are run from the **project root**:

```bash
./tools/desk <command>
```

Optional: add `tools/` to your `PATH` or symlink `tools/desk` as `desk` for shorter invocation.

## Configuration

Edit [`config.sh`](config.sh) before first use:

| Variable | Description |
|----------|-------------|
| `SSH_HOST` | Remote server hostname or IP |
| `SSH_PORT` | SSH port |
| `SSH_USER` | SSH username |
| `REMOTE_PROJECT` | Absolute path to the Laravel app on the server |
| `REMOTE_PUBLIC` | Absolute path to the web-accessible `public_html` directory |
| `INDEX_VENDOR_PATH` | Absolute path to `vendor/autoload.php` on the server (used in generated `index.php`) |
| `INDEX_BOOTSTRAP_PATH` | Absolute path to `bootstrap/app.php` on the server (used in generated `index.php`) |
| `PHP_BIN` | Path to PHP on the remote server |
| `COMPOSER_BIN` | Path to Composer on the remote server |
| `DEFAULT_BRANCH` | Git branch required for deployment (default: `main`) |

Shared helpers live in [`lib.sh`](lib.sh) and are sourced by each command script.

---

## Shared-hosting deployments

On shared hosting, the Laravel application root (`REMOTE_PROJECT`) typically sits **outside** the web-accessible document root (`REMOTE_PUBLIC`). The web server only serves files from `public_html`, so `index.php` must bootstrap Laravel using **absolute paths** back to the real project directory.

The toolkit handles this as follows:

1. **`copy_public` excludes `index.php`** — Laravel’s default `public/index.php` uses relative paths (`__DIR__.'/../vendor/autoload.php'`) that resolve incorrectly when the file lives in `public_html` while the app lives elsewhere.
2. **`generate_shared_hosting_index`** — During deploy, the toolkit backs up any existing `public_html/index.php` to `index.php.bak-YYYYMMDD-HHMMSS`, then renders [`templates/index.shared-hosting.php`](templates/index.shared-hosting.php) with your configured `INDEX_VENDOR_PATH` and `INDEX_BOOTSTRAP_PATH`.
3. **Post-generate validation** — Deploy aborts immediately if `vendor/autoload.php` or `bootstrap/app.php` is missing at the configured paths.
4. **Health check** — Succeeds only when `{APP_URL}/` returns HTTP `200` or `302` **and** the deployed `index.php` contains the configured vendor and bootstrap paths. If the HTTP request succeeds but `index.php` validation fails, the health check still fails.

Set `INDEX_VENDOR_PATH` and `INDEX_BOOTSTRAP_PATH` in [`config.sh`](config.sh) to match your server layout, for example:

```bash
REMOTE_PROJECT="/home/user/laravel/radium-desk"
REMOTE_PUBLIC="/home/user/domains/example.com/public_html"
INDEX_VENDOR_PATH="/home/user/laravel/radium-desk/vendor/autoload.php"
INDEX_BOOTSTRAP_PATH="/home/user/laravel/radium-desk/bootstrap/app.php"
```

---

## Commands

### `desk ssh`

Open an interactive SSH session on the remote server, starting in the Laravel project directory.

```bash
./tools/desk ssh
```

Use this for manual inspection, one-off artisan commands, or debugging on the server.

---

### `desk doctor`

Verify that local and remote deployment prerequisites are met.

```bash
./tools/desk doctor
```

**Checks performed:**

| Check | What it verifies |
|-------|------------------|
| SSH connectivity | Can connect to the remote server |
| PHP | Remote PHP binary exists and reports a version |
| Composer | Remote Composer is available |
| Laravel directory | `REMOTE_PROJECT` exists and contains `artisan` |
| `storage/` writable | Log and cache directories can be written |
| `bootstrap/cache/` writable | Framework cache directory can be written |
| Build manifest | Local `public/build/manifest.json` exists (run `npm run build` first) |
| `APP_ENV` | Set in the remote `.env` file |
| Database connection | `php artisan db:show` succeeds on the remote server |

Exits `0` when all checks pass, `1` when any check fails.

---

### `desk deploy`

Build frontend assets and deploy the application to production.

```bash
./tools/desk deploy
```

**Local preflight:**

1. Git working tree must be clean (no uncommitted changes)
2. Current branch must be `DEFAULT_BRANCH` (`main`)

**Deployment steps:**

1. Run `npm run build` locally
2. Upload `public/build/` to the remote public directory
3. Synchronize the full local `public/` directory to `REMOTE_PUBLIC` (excluding `index.php`)
4. `git pull origin main` on the remote Laravel project
5. `composer install --no-dev --optimize-autoloader`
6. `php artisan migrate --force`
7. `php artisan optimize`
8. Generate `public_html/index.php` from the shared-hosting template and validate bootstrap paths
9. Health check against `{APP_URL}/` (HTTP 200/302) and verify `index.php` references configured paths

Exits `0` on success, `1` on failure (including a failed health check).

**Before deploying:**

```bash
npm install          # if node_modules are missing
./tools/desk doctor  # verify prerequisites
git push origin main # ensure remote has your latest commits
./tools/desk deploy
```

---

### `desk logs`

View remote Laravel application logs.

```bash
# Follow log output (default)
./tools/desk logs

# Show last N lines without following
./tools/desk logs 200
```

Reads from `{REMOTE_PROJECT}/storage/logs/laravel.log`.

---

### `desk cache`

Clear and rebuild all Laravel caches on the remote server.

```bash
./tools/desk cache
```

Runs `php artisan optimize:clear` followed by `php artisan optimize`. Use after configuration changes or when stale caches cause unexpected behavior.

---

### `desk rollback`

Roll back the remote Git repository and refresh dependencies.

```bash
# Roll back one commit (default)
./tools/desk rollback

# Roll back three commits
./tools/desk rollback 3
```

**What it does:**

1. Shows the current remote commit
2. `git reset --hard HEAD~N` on the remote server
3. `composer install --no-dev --optimize-autoloader`
4. `php artisan migrate --force`
5. Clears and rebuilds caches
6. Runs a health check

> **Warning:** This performs a **hard reset** on the remote repository. Database migrations are **not** automatically reversed. Review migration impact before rolling back.

---

## File layout

```
tools/
├── desk              CLI entry point
├── config.sh         Server and path configuration
├── lib.sh            Shared helpers (ssh, rsync, health check, output)
├── templates/
│   └── index.shared-hosting.php   Generated public_html/index.php template
├── commands/
│   ├── deploy.sh     Production deployment
│   ├── doctor.sh     Prerequisite checks
│   ├── ssh.sh        Interactive SSH session
│   ├── logs.sh       Remote log tailing
│   ├── cache.sh      Remote cache management
│   └── rollback.sh   Remote git rollback
└── README.md         This file
```

## Troubleshooting

| Problem | Likely fix |
|---------|------------|
| `Git working tree is not clean` | Commit or stash local changes before deploying |
| `Must be on branch main` | `git checkout main` |
| `Local Vite build manifest exists` fails | Run `npm run build` locally |
| SSH connection fails | Verify `SSH_HOST`, `SSH_PORT`, and `SSH_USER` in `config.sh`; test with `desk ssh` |
| Health check fails | Check remote `.env` (`APP_URL`), web server config, `INDEX_VENDOR_PATH` / `INDEX_BOOTSTRAP_PATH`, and `desk logs` |
| Permission errors on storage | Fix ownership/permissions on the server for `storage/` and `bootstrap/cache/` |
