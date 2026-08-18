# P17-08-005 — Temporary Gmail inbound sync pause

**When:** 2026-08-17 10:03 IST (04:33 UTC)  
**Host:** VPS `radium-1` `/var/www/radium-desk`  
**Mode:** Operational pause only. No DNS, Cloudflare, LiteSpeed, MariaDB, cloudflared, queue, or scheduler cron changes. No git commit / `deskd`.

## Mechanism (inspected before change)

| Layer | What it was |
|---|---|
| Cron | Unchanged: `* * * * * …/bin/schedule-run.sh` plus `queue-worker.sh` |
| Laravel schedule | `bootstrap/app.php`: `inbound-email:sync-gmail` on `*/{interval} * * * *` |
| Interval | `inbound_email.gmail.schedule_interval_minutes` = **2** |
| Gate | `when(inbound_email.enabled && inbound_email.gmail.enabled)` |
| Live config before pause | `INBOUND_EMAIL_ENABLED=true`, `INBOUND_EMAIL_GMAIL_ENABLED=true` |
| Config cache | None (`bootstrap/cache/config.php` absent) |
| Running sync | None at inspect time — nothing killed |

Smallest reversible pause: **only** `INBOUND_EMAIL_GMAIL_ENABLED=false` in VPS `.env`. That makes `schedule:run` skip this event; a manual `artisan inbound-email:sync-gmail` exits immediately with “Inbound email Gmail sync is disabled.”

## Applied

```
INBOUND_EMAIL_GMAIL_ENABLED=false
```

`INBOUND_EMAIL_ENABLED` left **true**. Backup: `/home/ravi/p17-08-005-env-gmail-pause.bak` (copy of `.env` immediately before the edit).

## Verification (10:03–10:06 IST)

| Check | Result |
|---|---|
| Last Gmail spawn | **10:02:04 IST** (before pause) |
| Even-minute ticks 10:04 and 10:06 | Heartbeat / light-tick / reminders / snapshot / warm — **no** `sync-gmail` |
| `inbound-email:sync-gmail` process | **none** |
| Queue | Enrichment jobs still completing; crontab `queue-worker.sh` intact |
| `jobs` | **0** |
| `https://desk.radiumbox.com/up` | **200** (Cloudflare) |
| Tunnel | `/ready` 200, **4** HA connections, 0 errors |

Gmail is **not** resumed automatically.

## Restore after VPS upgrade

On the VPS, as `ravi`:

```bash
cd /var/www/radium-desk
# Option A — set the flag back
# Change only this line in .env:
#   INBOUND_EMAIL_GMAIL_ENABLED=false
# to:
#   INBOUND_EMAIL_GMAIL_ENABLED=true

# Option B — restore from the pre-pause copy (rewrites whole .env)
# cp -p /home/ravi/p17-08-005-env-gmail-pause.bak /var/www/radium-desk/.env

/usr/local/lsws/lsphp84/bin/php artisan tinker --execute='echo config("inbound_email.gmail.enabled") ? "gmail=true" : "gmail=false";'
```

No `config:clear` is required unless a config cache is introduced later. Confirm the next even minute in `storage/logs/schedule-run.log` shows `inbound-email:sync-gmail` again.
