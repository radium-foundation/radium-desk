# P-14-20 — RIP Hostinger recovery metadata

**Global ID:** P-14-20

Optional manifest key `hostinger_vps_recovery` (schema **1**, provider `hostinger`).

Build block from gate JSON:

```bash
./tools/desk hostinger-vps-snapshot-gate --json > /tmp/gate.json
python3 tools/lib/build_rip_hostinger_block.py /tmp/gate.json --source-commit "$(git rev-parse HEAD)"
```

`rip:verify-checkpoint` accepts manifests with or without the block; when present, `RipHostingerSnapshotPolicy` validates identity and forbids `restorable` status or token fields.