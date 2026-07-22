# Laravel Reverb — Development & Production Guide

## Development workflow

### 1. Install dependencies

```bash
composer install
npm install
```

### 2. Environment

```env
BROADCAST_CONNECTION=reverb
DASHBOARD_LIVE_MODE=auto
REVERB_APP_ID=local-app-id
REVERB_APP_KEY=local-app-key
REVERB_APP_SECRET=local-app-secret
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http
```

Generate credentials: `php artisan reverb:install`

### 3. Start development stack

```bash
composer dev
```

Runs app server, queue, Reverb, logs, and Vite concurrently.

## Production

Run Reverb under Supervisor alongside the queue worker. Set `REVERB_SCHEME=https` behind TLS.

See [dashboard-reverb-phase-4.2.md](./dashboard-reverb-phase-4.2.md) for architecture, rollback, and health checks.

## Ably cutover (Pusher-compat)

Dashboard Echo is dual-capable: `BROADCAST_CONNECTION=reverb` or `ably` with no event/channel changes.

**Prerequisites:** Ably Protocol Adapter (Pusher) enabled; `ably/ably-php` installed; API key publish+subscribe.

**Cutover**

1. Deploy dual-capable code while still on `BROADCAST_CONNECTION=reverb`; smoke-test.
2. Set `BROADCAST_CONNECTION=ably` and `ABLY_KEY=public:secret`, then `php artisan config:cache`.
3. Soak with `DASHBOARD_LIVE_MODE=auto` (polling safety net).
4. Confirm browser WSS to `realtime-pusher.ably.io`, then stop the Reverb process.

**Rollback (no redeploy)**

| Severity | Action |
|----------|--------|
| Soft | `DASHBOARD_LIVE_MODE=poll` |
| Transport | `BROADCAST_CONNECTION=reverb` + start `php artisan reverb:start` + `config:cache` |

Keep `REVERB_*` secrets available. `DASHBOARD_LIVE_MODE=reverb` still means websocket-only (transport may be Ably).
