# Central Wallet Reservation State Machine

Desk is the single source of truth for Central Wallet money. Reservations provide a
hold → commit → release lifecycle for spoke checkout without mutating the ledger until commit.

## States

| State | Description |
|-------|-------------|
| `active` | Funds are reserved; spendable balance is reduced |
| `committed` | Reservation converted to a ledger debit (terminal) |
| `released` | Hold cancelled before commit (terminal) |
| `expired` | TTL elapsed without commit (terminal) |

## Legal transitions (fail-closed)

```
ACTIVE → COMMITTED
ACTIVE → RELEASED
ACTIVE → EXPIRED
```

Illegal transitions return `422`.

## Balance semantics

- `ledger_balance` = posted credits/reversals/adjustments − posted debits
- `reserved_balance` = sum of `active` reservations with `expires_at > now()`
- `available_balance` = `ledger_balance − reserved_balance`

Reserving does **not** append ledger rows. Commit appends exactly one debit linked by `reservation_id`.

## API (`/api/central-wallet/v1`)

Authentication: Bearer `CENTRAL_WALLET_INTEGRATION_TOKEN` + `X-Site-Code`.

Feature flag: `CENTRAL_WALLET_RESERVATIONS_ENABLED=false` (default OFF).

### Create reservation

`POST /wallet-reservations`

```json
{
  "idempotency_key": "reserve-order-123",
  "central_wallet_id": "uuid",
  "amount": "25.00",
  "business_reference": "order:RBP123",
  "source_reference": "optional"
}
```

### Commit

`POST /wallet-reservations/{reservation_id}/commit`

```json
{ "idempotency_key": "commit-order-123" }
```

Idempotency namespace: `{caller_id}:commit` (separate from reserve namespace).

### Release

`POST /wallet-reservations/{reservation_id}/release`

```json
{ "idempotency_key": "release-order-123" }
```

### Expiry

Scheduled job `ExpireActiveReservationsJob` marks overdue `active` reservations as `expired`.

## Source system security

`source_system` on ledger writes is derived from authenticated `X-Site-Code`. Request body
values must match or are ignored; mismatches return `422 source_system_mismatch`.

## Reversal linkage

`entry_type: reversal` requires `original_ledger_entry_id` referencing a posted entry on the same wallet.
