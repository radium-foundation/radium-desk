# Pilot REF-67392 manifest — KVM delivery

The single-row pilot manifest for refund **387** (`REF-67392`) lives in Git at:

`storage/app/private/cw-pilot-refund-ref-67392-manifest.json`

## Rows SHA (immutable allowlist gate)

`7944bd96928b3c5eda656c17cbf69df2f846a48bcab5b47d686774a972033442`

## Whole-file SHA-256

`e9cd05225cf41401c10f7dcc2db1f8c6ab6a636f0cba0584150c294f1e39a6a2`

## Production sync

KVM deploy (`tools/commands/deploy-kvm.sh`) rsync includes **only** this file under `storage/app/private/` (alongside `release.json`). Other private storage paths remain excluded.

Default config path: `central_wallet.pilot_refund_migration.manifest_path` → the same filename under `storage/app/private/`.

Override only via `CENTRAL_WALLET_PILOT_REFUND_MIGRATION_MANIFEST_PATH` if needed; do not broaden private storage sync.

## Verification after deploy

```bash
php artisan central-wallet:pilot-refund-migration-preflight
```

Expect `journal_count_mismatch` until an owner-authorized import creates the Prepared journal row.
