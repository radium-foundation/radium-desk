# P-14-89 — RBP automatic hardware fulfilment + gap monitoring

## Policy (Option A)

Eligible **paid** RadiumBox **`RBP*`** channel ingest requests with **physical merchandise lines** open hardware fulfilment automatically (same lane as `RDE*`). **`RB*`** service orders remain excluded via `BusinessOrderId` prefix rules.

**Unpaid** `RBP*` ingest requests do **not** open fulfilment (stricter than legacy `RDE*` unpaid behaviour).

**Cancelled/voided** commerce (Box metadata order status, `failed`/`rejected` commerce status, non-paid payment) does **not** open fulfilment and is excluded from gap monitoring (**P-14-90**).

## Monitoring (read-only)

| Item | Value |
|------|--------|
| Command | `php artisan desk:monitor-rbp-hardware-fulfilment-gaps` |
| Config | `config/hardware_fulfilment.php` → `rbp_gap_monitor` |
| Grace | Default **30** minutes after commerce `created_at` |
| Schedule | `bootstrap/app.php` — stagger **+7** min off handoff reconcile cadence |
| Log | `storage/logs/rbp-hardware-fulfilment-gap-monitor.log` |

The monitor **reports** paid physical `RBP*` commerce rows without `hardware_fulfilments`; it does **not** auto-ingest or authorize recovery.

## RBP2138 recovery

`HardwareOwnerAuthorizedExistingCommerceProfiles` remains **RBP2138-only**. Isolated `desk:fulfil-hardware … --step=ingest` without `--payload` still requires Owner authorization for allowlisted ids.

## Operations

1. Review scheduled log for `gap_count > 0`.
2. Confirm payment status, physical lines, and whether channel ingest failed.
3. For historical gaps only, use P-14-59 Owner recovery (not automatic monitor remediation).