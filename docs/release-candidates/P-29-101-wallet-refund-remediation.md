# P-29-101 — Wallet refund remediation release bundle

**Desk branch:** `fix/p29-101-wallet-refund-guards` @ worktree `.worktrees/p29-101-wallet-refund` (base **`009d3e3c`** / v4.1.48)

| Change | Purpose |
|--------|---------|
| `RadiumBoxWalletRefundSpokeOrderResolver` | Map Desk `RB1010` → Box `RD66240` before API call |
| `RadiumBoxLegacyWalletRefundExecutionPolicy` | Fail-closed when desk-authoritative or `CENTRAL_WALLET_BLOCK_RADIUMBOX_LEGACY_*` |
| `WalletRefundExecutor` + `RadiumBoxWalletRefundClient` | Wire resolver + guard on Box legacy path |
| `config/central_wallet.php` | `block_radiumbox_legacy_spendable_without_desk_ledger` (default **false**) |

**Box branch:** `fix/p29-101-wallet-refund` @ worktree `.worktrees/p29-101-wallet-refund` (base **`launch/real-production`** @ **`24161b4c`**)

| Change | Purpose |
|--------|---------|
| `DeskWalletRefundBeneficiaryResolver` | Billing order owner wins over `RdService` userid |
| `DeskWalletRefundCreditService` | Message-based legacy idempotency when `desk_refund_reference` is null |
| `DeskLegacyWalletRefundReferenceMatch` | Shared message correlation helper |

**Ship together:** Desk caller order-id resolution + Box beneficiary + idempotency must deploy in one maintenance window.

**Prod flags (staged):** Enable `CENTRAL_WALLET_BLOCK_RADIUMBOX_LEGACY_WALLET_REFUND_WITHOUT_DESK_LEDGER` only after desk-first or authoritative ledger path is verified for Box.

**NOT in this bundle:** Financial correction for REF-67435 / #2586; `desk_refund_reference` backfill; P-27-27 backup/RIP execution.
