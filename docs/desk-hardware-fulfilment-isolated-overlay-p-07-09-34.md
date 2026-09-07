# Isolated hardware fulfilment surgical overlay — RadiumDesk-P-07-09-34

Date: 2026-09-07  
Source commit: `caec412f9c6f3d176291b71abcc5839ccab534ee`  
Mechanism: named-file rsync (no `--delete`). Not `./tools/desk deploy`.

---

## Why not full `desk deploy`

UPI/payment migrations remain **Pending**:

- `2026_09_04_220000_create_finance_bank_account_upi_profiles_table`
- `2026_09_04_220100_create_pos_payment_intents_table`
- `2026_09_04_220200_add_upi_intent_id_to_inventory_sales_table`

`caec412f` adds **no migrations**. Hardware P1/P2/P4/P5 were already **Ran**.

---

## Overlay set (12 files)

New:

- `app/Console/Commands/FulfilHardwareCommand.php`
- `app/Http/Controllers/Api/HardwareFulfilmentStatusController.php`
- `app/Services/HardwareFulfilment/HardwareFulfilmentInboundCallbackService.php`
- `app/Services/HardwareFulfilment/HardwareFulfilmentIsolatedWorkflowService.php`
- `app/Services/Shipping/HttpShiprocketGateway.php`

Replaced (pre-overlay hashes matched `4c2d28e7` / P7B):

- `app/Providers/AppServiceProvider.php`
- `app/Services/HardwareFulfilment/HardwareFulfilmentCallbackProcessor.php`
- `app/Services/HardwareFulfilment/HardwareFulfilmentEligibility.php`
- `app/Services/HardwareFulfilment/HardwareFulfilmentWorkflowService.php`
- `config/hardware_fulfilment.php`
- `config/shipping.php`
- `routes/api.php`

Tests/docs were not copied. `.env` was not copied.

Backup: `storage/app/private/overlays/p-07-09-34-20260907T144305Z`

Then `artisan optimize:clear` + `optimize`.

---

## After

| Check | Result |
|-------|--------|
| `desk:fulfil-hardware` | PRESENT |
| no-id / `all` / `*` | refused, exit 1 |
| `markReady` / `markShipped` | YES |
| `HttpShiprocketGateway` class | PRESENT, **not bound** |
| Bound gateway | `NullShiprocketGateway` |
| Bound callback | `NullBoxFulfilmentCallbackGateway` |
| shipping.enabled / provider / http_enabled | false / none / false |
| API email/password | EMPTY |
| Pickup | RADDELHI / RADIUMUM |
| auto POS / ingest / worker / correlate | all false |
| callback enabled / inbound / URL / secret | false / false / EMPTY / EMPTY |
| Desk `POST /api/desk/fulfilment-status` | route exists, **404 disabled** |
| Box receiver | not modified |
| hardware fulfilments / serials / shipments | 0 / 0 / 0 |
| `radiumbox_com` commerce | 0 |
| issued invoices | 229 unchanged |
| RDE318400 commerce/HF | 0 / 0 |
| UPI migrations | still Pending |
| `/up` `/login` | 200 |

`release.json` unchanged: 4.0.67 / `5d14a582`.

No `desk:fulfil-hardware RDE318400`. Fake dry-run `RDE900000` failed closed (no record).
