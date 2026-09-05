# Old Admin replacement deploy — RadiumDesk-P-05-09-22

**Date:** 2026-09-05  
**Primary ID:** `RadiumDesk-P-05-09-22`  
**Companions:** `rdservice.in-P05-09-02`, `radiumbox.com-P-05-09-18`  
**Implementation:** `RadiumDesk-P-05-09-21` @ `743d77b7`

Surgical overlay. Not full `deskd`. No mint. No IRN. No Cashfree change. No DLQ flush. Old Admin DNS not touched.

---

## Production boundary

| App | Path | Host | Mechanism |
|---|---|---|---|
| Radium Desk | `/var/www/radium-desk` | KVM8 `srv1910783` / `187.127.129.16` | Named-file rsync + env + `config:cache` / `route:cache` + worker restart |
| rdservice.in | `/var/www/rdservice.in` | same | Named-file overlay + shared token |
| radiumbox.com | `/var/www/radiumbox.com` | same | Named-file overlay + shared token |
| rdservice.net | `/var/www/rdservice.net.prod` | same | No code change. Existing token reused |

`release.json` remains `5d14a582` (last full rsync). Overlay files match `743d77b7`.

Backup: `/home/ravi/desk-prod-backups/p-05-09-22-20260905T181013Z`

---

## Production env (Desk)

```
RADIUMBOX_ENABLED=false
RADIUMBOX_BASE_URL=
RADIUMBOX_ADMIN_FALLBACK_ENABLED=false
RDSERVICE_ENABLED=true
RDSERVICE_BASE_URL=http://127.0.0.1
RDSERVICE_HOST=rdservice.net
RDSERVICE_TIMEOUT_SECONDS=45
RDSERVICE_IN_LOOKUP_ENABLED=true
RDSERVICE_IN_BASE_URL=http://127.0.0.1
RDSERVICE_IN_HOST=rdservice.in
RADIUMBOX_STOREFRONT_LOOKUP_ENABLED=true
RADIUMBOX_STOREFRONT_BASE_URL=http://127.0.0.1
RADIUMBOX_STOREFRONT_HOST=radiumbox.com
```

Token: existing `DESK_ORDER_API_TOKEN` copied to in/Box. Length 64. Hash prefix `2b817165cbe9` matches Desk/net/in/Box. Value never printed.

Lookup timeouts raised to 45s after production spoke calls measured 25–35s.

---

## Live verification

| Check | Result |
|---|---|
| `https://desk.radiumbox.com/up` | 200 |
| `https://radiumbox.com/up` | 302 → `/` 200 (no Laravel `/up` on Box) |
| Worker | `RUNNING` after restart |
| Desk `fetchInteractive(RD3449705)` | invoice `INV6745886`, Paid/Completed, serial `7710951`, customer `Nareshkumar`, product `MFS110` |
| Box historical `INV6745886` | eligibility `historical_invoice`, `orders_id` 268507, `read_only` true, number exact |
| Statutory `INV-07671` | rejected (`statutory_invoice` / 422) |
| Unauth Finance historical GET/print | 302 `/login` |
| Spoke unauth / wrong token | 401 |
| in `RD3449705` | 404 (net-owned; allow-list holds) |
| in `RD3511987` | 200 |
| `fallbackToAdmin` | false |
| `admin.radiumbox.com` after 23:46 IST | **0** new log lines |
| Statutory invoices | 0 before and after |
| `failed_jobs` | 57 — **not flushed, not replayed** |
| Token in logs | 0 |

Last Old Admin HTTP in Desk logs: 23:36:59 IST (pre-cutover worker). Not this verification.

---

## Rollback (not executed)

Restore files + `.env` + `config.php` + `routes-v7.php` from the backup directory. `php artisan config:cache && php artisan route:cache` in `/var/www/radium-desk`. `chmod 600` env/config. `sudo supervisorctl restart radium-desk-queue-worker`. Do not flush DLQ.

---

## Intentionally not done

- Full `deskd` / new release tag
- Owner-browser reprint session (no production operator login)
- DLQ / Admin `526` replay
- Cashfree notify/return inspection or change
- Old Admin DNS / EC2
- AWS dump import
- Migrations / indexes for slow `rdorderid` scans
