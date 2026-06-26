# Upgrade Notes — v3.5

## Pre-Deployment Checklist

1. **Run database migrations** (if any pending from prior phases):
   ```bash
   php artisan migrate --force
   ```

2. **Build frontend assets:**
   ```bash
   npm ci
   npm run build
   ```

3. **Verify test suite:**
   ```bash
   php artisan test
   npm test
   ```

4. **Historical data sync (recommended one-time):**
   ```bash
   php artisan service-cases:sync-closed-status --dry-run
   php artisan service-cases:sync-closed-status
   ```

## Deployment Steps

1. Deploy application code and compiled assets (`public/build/`).
2. Clear config and view caches:
   ```bash
   php artisan config:cache
   php artisan view:cache
   ```
3. Restart queue workers and PHP-FPM if applicable.
4. No new environment variables required for v3.5.

## Behavior Changes

### Quick Create Redirect (tests only)

Production behavior is unchanged. If you have external integrations or E2E tests expecting redirect to the service case show page after quick create, update them to expect redirect to `/dashboard` with session keys:

- `status: service-case-created`
- `service_case_reference`
- `reopen_quick_create: true`

### Workspace Modal Bootstrap

The `data-workspace-fragment-loader` attribute has been removed from the workspace modal host. Only `data-workspace-modal-host` is required. No template changes needed if using the included layout partial.

## Rollback

v3.5 is backward compatible with v3.4 data and routes. Rollback procedure:

1. Redeploy previous release tag.
2. Rebuild frontend from previous `package-lock.json` if JS changed.
3. No database rollback required.

## Not Included in v3.5

Do not expect these in this release:

- Laravel Reverb / WebSockets / Livewire
- New UI surfaces or features
- Real-time push notifications (polling remains the transport)
