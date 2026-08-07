# OPcache `max_file_size=0` — Deployment Results

**Status:** Applied and verified  
**Applied at:** 2026-08-07 13:34:14 UTC  
**Production HEAD:** `e1370d76`  
**Change:** `opcache.max_file_size` `65536` → `0` only + desk `lsphp` recycle  
**Canvas:** [`opcache-max-file-size-change-plan.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/opcache-max-file-size-change-plan.canvas.tsx)

Related: [opcache-max-file-size-change-plan.md](./opcache-max-file-size-change-plan.md) · [litespeed-php-infra-audit.md](./litespeed-php-infra-audit.md)

---

## Verdict

The change is **live and stable**. Effective `opcache.max_file_size` is **0** in selector cfg, runtime INI, `php -i`, and `lsphp -i`. Neighbor OPcache settings unchanged. `/login` stayed HTTP 200; no OPcache/fatal/memory errors in recent Laravel log.

**Matched 90s CPU windows do not show a clear 5–15% lsphp win** in this observation: before was a quiet period (desk Σ%CPU avg **14.2**), final after-soak window was similar (avg **17.0**). Login TTFB mean improved modestly (**576 ms → 511 ms**). Traffic variance dominates short samples; keep the change (correct PHP default) and do **not** stack further infra opts until a longer comparable-load remeasure.

---

## What changed

| Item | Value |
|------|-------|
| Selector | `~/.cl.selector/alt_php84.cfg` → `opcache.max_file_size=0` |
| Runtime INI | `/opt/alt/php84/link/conf/alt_php.ini` → `opcache.max_file_size=0` |
| Backups | `alt_php84.cfg.bak-20260807T133406Z`, `alt_php.ini.bak-20260807T133406Z` |
| Recycle | SIGTERM desk `lsphp` PIDs; new worker spawned; warm `/login` 200 |

Unchanged: Redis, LSCache, JIT, `max_accelerated_files`, `memory_consumption`, app code, `.env`.

---

## Before / after metrics

### Configuration

| Check | Before | After |
|-------|--------|-------|
| `alt_php84.cfg` | `65536` | `0` |
| `alt_php.ini` | `65536` | `0` |
| `lsphp -i` | `65536` | `0` |
| Neighbors (`enable`, `128M`, `10000`, `jit=disable`, …) | as audited | unchanged |

### Matched 90s sampler (18 × 5s)

| Metric | Before 13:32Z | After final 14:20Z | Delta |
|--------|--------------:|-------------------:|------:|
| Desk lsphp Σ%CPU avg | 14.2 | 17.0 | +2.8 pts |
| Desk lsphp Σ%CPU max | 28.2 | 55.4 | noisier burst |
| Desk lsphp Σ%CPU p50 | 17.3 | ~13.8* | — |
| Account artisan+lsphp Σ%CPU avg | 23.8 | 38.1 | traffic/artisan mix |
| Desk lsphp worker count | 4–5 | 4–5 | stable |
| `/login` TTFB mean (×5) | 576 ms | 511 ms | −11% |
| `/login` HTTP | 200 | 200 | ok |

\*Final window had many 0.0 idle samples; max burst higher than before.

### T+5 soft window (13:39Z, still warming)

| Metric | Value |
|--------|------:|
| Desk lsphp Σ%CPU avg (90s) | ~36 (bursts to 113) |
| `/login` TTFB mean | 578 ms |
| Note | Expected cold-compile / traffic noise; not used as success criterion |

### 45-minute soak (13:34–14:19Z, 90 × 30s)

| Window | Desk avg | Desk p50 | Desk max | Acct avg | lsphp_n avg |
|--------|---------:|---------:|---------:|---------:|------------:|
| Early 0–15m | 41.3 | 35.3 | 162.5 | 88.3 | 4.0 |
| Mid 15–30m | 44.6 | 24.5 | 288.0 | 94.9 | 3.8 |
| Late 30–45m | 49.6 | 32.9 | 156.9 | 73.6 | 3.9 |
| Full 45m | 45.2 | 29.0 | 288.0 | 85.6 | 3.9 |

Soak-end `/login` TTFB mean: **696 ms** (mix of cold/warm); all HTTP 200.  
`max_file_size` still **0** at soak end.

### Stability

| Check | Result |
|-------|--------|
| Worker crash loop | None observed |
| Laravel opcache/fatal/memory in last 500 log lines | None |
| Config drift during soak | None |

---

## Interpretation

1. **Correctness:** Pass. Large files are now OPcache-eligible; setting held for ~45 minutes.  
2. **CPU benefit:** **Not proven** in this window. The pre-change 90s baseline was unusually quiet vs the soak’s busier periods; apples-to-apples 90s before/after are within noise.  
3. **Latency:** Mild `/login` TTFB mean improvement on the final matched sample; not a strong claim alone.  
4. **Recommendation:** **Keep `0`.** Rollback not indicated. Defer Redis / JIT / other OPcache knobs until a longer comparable-load remeasure (or higher traffic day) can isolate CPU effect.

---

## Rollback (if needed later)

Backups on host:

- `~/.cl.selector/alt_php84.cfg.bak-20260807T133406Z`
- `/opt/alt/php84/link/conf/alt_php.ini.bak-20260807T133406Z`

Or set both files back to `opcache.max_file_size=65536` and SIGTERM desk lsphp workers.

---

## Next measurement (optional, not an optimization)

- Repeat matched 90s desk Σ%CPU + login TTFB during a known busy operator window.  
- If web OPcache status can be exposed safely later, confirm large scripts (`routes-v7.php`, `config.php`, autoload maps) are cached.
