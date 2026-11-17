# P-17-40 — Retire Upload Package Photo feature

**Ledger:** `RadiumDesk-P-17-40`  
**Production baseline:** `v4.1.85` / `b87b10c6f`  
**Investigation:** P-17-39 (read-only)  
**Owner approval:** Retire feature; remove mark-shipped and external-dispatch photo gates; preserve historical media and audit.

## Before → after

| Area | Before | After |
|------|--------|-------|
| Needs Action filter | `package_photo_pending` chip + counts | Removed; legacy `hw_filter=package_photo_pending` falls through to Needs Action |
| Upload UI | Dashboard dialog, fulfilment show form | Removed |
| `POST …/package-evidence` | Accepted new photos | Route removed → **404** |
| `GET …/package-evidence/{id}` | Served stored file | **Unchanged** (historical read-only) |
| User `markShipped()` | Required package evidence | **No** photo check |
| `canExternalDispatch` | Required `packagePhotoRecorded` | **No** photo check |
| SQL shipping queue | Excluded photo-pending rows | Includes ready-for-pickup rows without photo |
| DB / files | `hardware_fulfilment_package_evidences` + private disk | **Unchanged** (no migration, no deletion) |

## Pending-case behaviour (production)

~138 former `package_photo_pending` rows reclassify via classifier/SQL only (no bulk DB updates): manifest-complete, AWB-assigned fulfilments move into **Shipping** / **Ready for Pickup** queues when other eligibility passes.

## Historical access

- Table: `hardware_fulfilment_package_evidences`
- Read: `GET inventory/hardware-fulfilments/{fulfilment}/package-evidence/{evidence}` (`hardware-fulfilments.package-evidence.show`)
- Fulfilment show: “Package Photo (archived)” + View Photo when evidence exists

## Deploy readiness (no deploy in P-17-40)

- **Release:** `v4.1.86` (unreleased metadata)  
- **Migrations:** none  
- **Production data mutation:** none  
- **Rollback:** redeploy `v4.1.85` / `b87b10c6f`  
- **Post-deploy checks:** mark shipped / external dispatch without photos; confirm no upload UI; bookmarked `package_photo_pending` URL shows Needs Action; spot-check historical View Photo links