# P15-08-038 — New Tier-1 extract (no apply, no VPS transport)

Canvas: [`p15-08-038-new-tier1-extract.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p15-08-038-new-tier1-extract.canvas.tsx)

## Scope decision

Safest new-generation scope is **all current laptop Tier-1 tables (32)**, using **VPS-authoritative checkpoints**, because:

- The blocked chain is `bonvoice_webhook_logs → bonvoice_call_events → incidents → incident_bonvoice_call_links`.
- Closed `depends_on` ancestors are `users`, `device_models`, `orders` (Hostinger kept writing).
- Remaining unapplied 90fe9d Tier-1 tables (journals, refunds, close outcomes, etc.) have no VPS checkpoint; a fresh snapshot avoids mixing stale 90fe9d chunks with a later apply.
- Tables already checkpointed extract as **deltas** only.

Invoked `TableDeltaExtractor::extract($manifest, $generationId, null, 1, $vpsCheckpoints)`. Did **not** call `ExtractFileTransporter`. Did **not** apply.

## Generation

| Field | Value |
|---|---|
| ID | `tier1-rehearsal-20260815-145625-46fb82` |
| Snapshot | `2026-08-15T20:26:26+05:30` |
| Direction | `hostinger_to_vps` |
| Manifest SHA-256 | `ffd2152e268c86cc7d0fbebdb8c202257b0b582629d6290672ec888e84eb5580` |
| Tables | 32 |
| Chunks | **59/59**, 0 missing, 0 mismatch |
| Extracted rows | 91,339 |
| Hostinger inbox | `…/inbox/tier1-rehearsal-20260815-145625-46fb82/` |
| Laptop staging | `storage/app/private/db-sync/staging/tier1-rehearsal-20260815-145625-46fb82/` (rsync pull only) |

## Required parents

All present: `bonvoice_webhook_logs` (25,463 / 13 chunks), `bonvoice_call_events` (24,970 / 13), `incidents` (391 delta / 1), `incident_bonvoice_call_links` (528 delta / 1). **No required parent is absent.**

## Apply order (VPS / laptop config, not Hostinger extract iteration)

`… orders → bonvoice_webhook_logs → bonvoice_call_events → incidents → cashfree_webhook_logs → incident_bonvoice_call_links …`

Hostinger `remote_extract.php` still uses Hostinger’s older topological listing for iteration inside a consistent snapshot. That does not change apply order on VPS.

## 90fe9d / VPS

- VPS inbox still **only** `tier1-rehearsal-20260815-085333-90fe9d`
- Manifest SHA unchanged: `db80fcc1f65dd10726547f31b88427580c3b4f3fd6fa3904fca1ad008580b518`
- 18 checkpoints fingerprints unchanged
- dark=`true`
- Hostinger 90fe9d SHA unchanged

Not applied. Not transported to VPS.
