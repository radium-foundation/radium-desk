# P-17-46 — v4.1.86 post-deploy UAT (package photo retirement)

**Requires:** authenticated hardware operator session on production after **v4.1.86** deploy.

## Must verify

| # | Check | Pass criteria |
|---|--------|----------------|
| 1 | Hardware Needs Action filters | **No** “Package Photo Pending” chip; no `package_photo_pending` count |
| 2 | Fulfilment workspace | **No** “Upload Package Photo” button or dialog |
| 3 | Route | `POST …/package-evidence` → **404** or method not allowed |
| 4 | Historical access | Open fulfilment with existing evidence → **View Photo** / `GET` returns **200** JPEG |
| 5 | Mark shipped | Ready row without package photo can mark shipped when other rules satisfied |
| 6 | External dispatch | Eligibility does not block on missing package photo |
| 7 | Shipping queues | Former photo-pending AWB rows appear in Shipping / Ready for Pickup when otherwise eligible (**no** DB bulk update expected) |

## Must not change (spot-check)

- Package evidence table row count **301**
- Audit events `hardware_package_evidence_recorded` count **301**
- Files under `private/hardware-fulfilment-evidence/` count **301**

## Out of scope

- Archive or delete historical photos (P-17-45 / separate Owner gate)
- P-17-43 recovery cohort processing (do not re-run recovery commands during UAT)