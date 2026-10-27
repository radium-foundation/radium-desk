# Desk-central driver management

See paired document on `radiumbox.com`: `docs/architecture/desk-central-driver-management.md`.

## Desk module (P-21-58)

- **Permission:** `drivers.manage.radiumbox` (`RolePermissionSeeder::PERMISSION_DRIVERS_MANAGE_RADIUMBOX`)
- **Routes:** `/driver-management/radiumbox` (auth + active + permission middleware)
- **Navigation:** Control & Admin tab **Driver files (RadiumBox)** (`control-and-admin-workspace-nav.blade.php`) when `RADIUMBOX_DRIVER_MANAGEMENT_ENABLED=true` **and** the user has `drivers.manage.radiumbox` (authorized users may still open the route when the flag is off; page shows not configured).
- **Client:** `RadiumBoxDriverUploadClient` — server-side Bearer token; browser never sees secrets
- **Config:** `config/driver_management.php` — `RADIUMBOX_DRIVER_MANAGEMENT_ENABLED`
- **CI:** `tests/Feature/DriverManagement/` in **`release-rip`** (every `main` PR/push) and path-filtered **`driver-management-rip`** workflow

Granted by default to **superadmin** and **operations_admin** only. Not assigned to support/agent roles. **No named-user grants** (e.g. Shubhanshi) without Owner verification.

## Future sites

Add spoke entries under `driver_management` with distinct permissions (`drivers.manage.rdservice_in`, etc.).
