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
