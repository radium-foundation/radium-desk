# OPcache `max_file_size` Change Plan (P0 only)

**Status:** Applied 2026-08-07 13:34:14 UTC — results in [opcache-max-file-size-change-results.md](./opcache-max-file-size-change-results.md)  
**When prepared:** 2026-08-07  
**Production HEAD (verified):** `e1370d76`  
**Host:** `desk.radiumbox.com` → `in-mum2-web2219.main-hosting.eu` (Hostinger / CloudLinux / LiteSpeed LSAPI)  
**Canvas:** [`opcache-max-file-size-change-plan.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/opcache-max-file-size-change-plan.canvas.tsx)

Related: [opcache-max-file-size-change-results.md](./opcache-max-file-size-change-results.md) · [litespeed-php-infra-audit.md](./litespeed-php-infra-audit.md) · [p0-lsphp-http-cpu-attribution-investigation.md](./p0-lsphp-http-cpu-attribution-investigation.md)

---

## Scope

**Only** this change:

```ini
opcache.max_file_size=0
```

Explicitly **out of scope** (do not touch):

- Redis / cache / session / queue drivers  
- LSCache  
- JIT / `validate_timestamps` / `max_accelerated_files` / `interned_strings_buffer`  
- Application code, Laravel config, deploy scripts  

---

## 1. Current configuration (verified live)

Read-only SSH probe on 2026-08-07 against production HEAD `e1370d76`.

| Location | Role | `opcache.max_file_size` |
|----------|------|------------------------:|
| `~/.cl.selector/alt_php84.cfg` (line 34) | CloudLinux PHP Selector custom options (source of truth for account PHP 8.4) | **65536** |
| `/opt/alt/php84/link/conf/alt_php.ini` (line 34) | Generated runtime INI loaded by PHP/lsphp | **65536** |
| `/opt/alt/php84/usr/bin/php -i` | CLI effective value | **65536** |
| `/opt/alt/php84/usr/bin/lsphp -i` | Web SAPI effective value | **65536** |

Surrounding OPcache settings (unchanged by this plan):

| Setting | Current |
|---------|--------:|
| `opcache.enable` | On |
| `opcache.memory_consumption` | 128M |
| `opcache.max_accelerated_files` | 10000 |
| `opcache.interned_strings_buffer` | 8 |
| `opcache.validate_timestamps` | On |
| `opcache.revalidate_freq` | 2 |
| `opcache.jit` | disable |

**SAPI note:** Production uses **LiteSpeed LSAPI** (`lsphp`), not PHP-FPM. There is no FPM pool to restart.

---

## 2. Proposed configuration

Minimal diff in `~/.cl.selector/alt_php84.cfg` (and the regenerated `alt_php.ini`):

```diff
-opcache.max_file_size=65536
+opcache.max_file_size=0
```

| Field | Current | Proposed |
|-------|--------:|---------:|
| `opcache.max_file_size` | `65536` (64 KB) | `0` (no size limit — PHP default) |

Meaning of `0` (PHP docs): no maximum file size for OPcache admission — large files are eligible for bytecode caching.

---

## 3. Why this is needed

With `65536`, every `.php` file larger than 64 KB is **excluded** from OPcache and recompiled (or re-parsed) on the hot path.

Live count on deploy tree: **29** PHP files > 64 KB (~5.9 MB source). Hot-path exclusions include:

| File | Size |
|------|-----:|
| `vendor/composer/autoload_static.php` | 949 KB |
| `vendor/composer/autoload_classmap.php` | 860 KB |
| `vendor/nesbot/carbon/.../CarbonInterface.php` | 444 KB |
| `bootstrap/cache/routes-v7.php` | 377 KB |
| `vendor/nesbot/carbon/.../Carbon.php` | 291 KB |
| `vendor/laravel/.../Query/Builder.php` | 149 KB |
| `bootstrap/cache/config.php` | 134 KB |
| `vendor/laravel/.../Eloquent/Model.php` | 80 KB |
| `vendor/symfony/http-foundation/Request.php` | 78 KB |

Laravel’s own optimized caches (`routes-v7.php`, `config.php`) and Composer classmaps are ironically the largest excluded artifacts.

---

## 4. Why this is safe

1. **PHP default.** `opcache.max_file_size=0` is the upstream default (“cache all files by size”). Hostinger/CloudLinux currently overrides it to `65536`.
2. **No application change.** Opcode caching does not alter PHP semantics; it only stores compiled bytecode.
3. **Memory headroom.** OPcache SHM is **128M**. Admitting ~29 large files is well within typical budgets; CLI sample previously showed ~34 MB used with ~1.3k scripts (web SHM is separate but same order of magnitude).
4. **File-slot headroom.** ~7916 app+vendor PHP files vs `max_accelerated_files=10000`. Adding ~29 large scripts does not breach the slot cap.
5. **Reversible in one line.** Rollback is the exact inverse value (`65536`) plus the same lsphp recycle.
6. **No Redis / LSCache / FPM / app deploy coupling.** Isolated PHP selector knob.

Residual risk (acceptable for this change): first requests after recycle pay a one-time compile cost for newly admitted large files; OPcache memory utilization rises. If SHM pressure appears later, the next infra step would be raising `memory_consumption` / `max_accelerated_files` — **not** part of this change.

---

## 5. Expected impact

| Metric | Estimate |
|--------|----------|
| lsphp / account CPU | **5–15%** reduction (infra audit P0 band) |
| Latency | Modest improvement on request paths that load routes/config/Carbon/Query Builder |
| Error rate | No expected change |
| What will **not** change | Redis still down; LSCache still unused; Operations Live app cost still dominant after infra |

Do not stack this estimate with Redis/JIT numbers without independent measurement.

---

## 6. Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Brief CPU/latency spike while lsphp workers recompile large files into OPcache | Medium (seconds–minutes) | Deploy in a quiet window; warm `/login` + one authenticated page |
| OPcache memory closer to 128M → more evictions | Low–medium | Verify after soak; if needed, later raise `memory_consumption` (separate change) |
| Selector edit not applied to runtime INI | Low | Verify both `.cfg` and `php -i` / `lsphp -i` show `0` |
| Accidental edit of other PHP selector knobs | Process | Change **only** `max_file_size` |
| Mistaken full LiteSpeed / FPM restart attempt | Process | Use account-level lsphp recycle only (below) |

---

## 7. Restart procedure

| Action | Required? |
|--------|-----------|
| PHP-FPM restart | **No** — FPM is not used |
| Full LiteSpeed (`lswsctrl`) restart | **No** — not available / not needed on shared hosting |
| Recycle account `lsphp` workers | **Yes** — so new INI is loaded into web SAPI |

After the selector value is applied and `alt_php.ini` shows `0`:

1. Prefer Hostinger hPanel **Restart PHP** / equivalent for the site if present.  
2. Or gracefully stop desk `lsphp` workers so LiteSpeed respawns them with the new INI, e.g. identify PIDs matching `lsphp:…desk.radiumbox.com` and `kill` them (SIGTERM). Do **not** `kill -9` unless a worker is stuck.  
3. Confirm new workers exist and `lsphp -i` still reports `opcache.max_file_size => 0`.

CLI (`php -i`) updates when the INI is regenerated; web workers only pick up the new value after recycle.

---

## 8. Exact deployment steps (do not run until approved)

### A. Pre-change baseline (required)

Record timestamp and metrics (see §10).

### B. Apply (choose one path)

**Preferred — hPanel (safer on Hostinger):**

1. hPanel → **Advanced** → **PHP Configuration** (PHP Selector) for PHP **8.4**.  
2. Set **`opcache.max_file_size`** to **`0`**.  
3. Save. Confirm Hostinger regenerates `/opt/alt/php84/link/conf/alt_php.ini`.  
4. Recycle lsphp (§7).

**Alternative — SSH (same one-line intent):**

```bash
# On production account (after explicit approval)
CFG="$HOME/.cl.selector/alt_php84.cfg"
cp -a "$CFG" "$HOME/.cl.selector/alt_php84.cfg.bak-$(date -u +%Y%m%dT%H%M%SZ)"

# Change ONLY this directive
sed -i 's/^opcache.max_file_size=65536$/opcache.max_file_size=0/' "$CFG"

# Confirm cfg
grep '^opcache.max_file_size=' "$CFG"
# expect: opcache.max_file_size=0
```

Then ensure the **runtime** INI is updated. On this host, `/opt/alt/php84/link/conf/alt_php.ini` currently mirrors the selector block. If it still shows `65536` after editing `.cfg`:

- Re-save PHP 8.4 options once in hPanel (forces regenerate), **or**  
- If account policy allows and the file is the account-linked INI, update the same single line in `alt_php.ini` to match (still only `max_file_size`).

Finally recycle lsphp (§7).

### C. Do not

- Edit Redis, `.env`, Laravel code, LSCache, or other OPcache knobs.  
- Run `deskd` / app deploy for this change (not required).  
- Enable JIT in the same window.

---

## 9. Exact verification steps

Run after recycle:

```bash
# 1) Selector source
grep '^opcache.max_file_size=' ~/.cl.selector/alt_php84.cfg
# expect: opcache.max_file_size=0

# 2) Runtime INI
grep '^opcache.max_file_size=' /opt/alt/php84/link/conf/alt_php.ini
# expect: opcache.max_file_size=0

# 3) CLI + lsphp effective
/opt/alt/php84/usr/bin/php -i | grep '^opcache.max_file_size'
/opt/alt/php84/usr/bin/lsphp -i | grep '^opcache.max_file_size'
# expect: opcache.max_file_size => 0 => 0

# 4) Unchanged neighbors (sanity)
/opt/alt/php84/usr/bin/lsphp -i | grep -E '^opcache\.(enable|memory_consumption|max_accelerated_files|jit) '

# 5) Smoke
curl -s -o /dev/null -w '%{http_code}\n' --max-time 30 https://desk.radiumbox.com/login
# expect: 200

# 6) Optional: confirm large hot files exist (still present; now cache-eligible)
stat -c '%s %n' \
  /home/u215544208/laravel/radium-desk/bootstrap/cache/routes-v7.php \
  /home/u215544208/laravel/radium-desk/bootstrap/cache/config.php
```

Pass criteria:

- [ ] All four sources report `0`  
- [ ] Other OPcache settings unchanged  
- [ ] `/login` returns 200  
- [ ] Desk pages load for an authenticated spot-check  
- [ ] No new spike of 5xx in logs during soak  

---

## 10. Benchmark checklist (before / after)

Capture the **same** window length and roughly similar operator activity.

### Before (T−15 to T0)

| Check | How | Record |
|-------|-----|--------|
| Timestamp (UTC) | `date -u` | |
| HEAD | `git rev-parse --short HEAD` in project dir | `e1370d76` expected |
| `opcache.max_file_size` | `lsphp -i` | `65536` |
| Load average | `uptime` | |
| Desk lsphp count | `ps` count `lsphp:…desk.radiumbox.com` | |
| Desk lsphp Σ%CPU | `ps` sum over 60–90s samples | |
| Account artisan+lsphp Σ%CPU | existing sampler if available | |
| `/login` TTFB | `curl -o /dev/null -s -w '%{time_starttransfer}\n'` ×5 | |
| Authenticated page TTFB | one stable page (e.g. dashboard) ×5 if session available | |
| LVE / Hostinger CPU graph | hPanel screenshot or note | |
| Laravel log errors (15m) | `storage/logs/laravel.log` tail | |

### After (T+5 soft, T+30 soak)

Repeat the same table. Additionally:

| Check | Pass signal |
|-------|-------------|
| Effective `max_file_size` | `0` everywhere |
| lsphp Σ%CPU vs before | Down or flat at similar traffic (target band −5% to −15%) |
| Login / auth smoke | 200 / no functional regression |
| Error logs | No new OPcache / fatal pattern |
| Worker stability | 2–4 desk workers typical; no crash loop |

### Interpretation guardrails

- Shared-host **load average** is noisy (other tenants) — prefer **account lsphp Σ%CPU** and request TTFB.  
- Do not attribute Redis/app changes — none are in this rollout.  
- First 2–5 minutes post-recycle may be worse (cold OPcache); judge from T+5 onward.

---

## 11. Rollback

If verification fails, CPU worsens after soak, or pages misbehave:

```bash
CFG="$HOME/.cl.selector/alt_php84.cfg"
sed -i 's/^opcache.max_file_size=0$/opcache.max_file_size=65536/' "$CFG"
# Ensure alt_php.ini matches (hPanel re-save or same one-line edit)
grep '^opcache.max_file_size=' "$CFG" /opt/alt/php84/link/conf/alt_php.ini
# expect 65536

# Recycle lsphp again (§7)
/opt/alt/php84/usr/bin/lsphp -i | grep '^opcache.max_file_size'
# expect: 65536
```

Or restore from the timestamped `.bak-*` copy of `alt_php84.cfg`, regenerate/sync INI, recycle lsphp.

Rollback does **not** require git revert or `deskd`.

---

## Approval gate

Approved and applied 2026-08-07 13:34:14 UTC. See [opcache-max-file-size-change-results.md](./opcache-max-file-size-change-results.md).
