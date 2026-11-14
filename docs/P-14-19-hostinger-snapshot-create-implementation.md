# P-14-19 — Hostinger snapshot create implementation

**Global ID:** P-14-19

`desk hostinger-vps-snapshot-create`:

- Default: dry-run plan (GET only).
- `--execute`: POST + re-GET verify when Owner env set.

## Live POST

**BLOCKED** until explicit Owner authorization in chat naming VPS, purpose, overwrite consent, and shared-VPS impact.

Mocked unit tests cover POST path; no production snapshot mutation in CI.