# Inter-Branch Transfer (Radium Desk)

**Prompt:** RadiumDesk-P-04-10-116  
**Status:** Implemented in code/tests only — not deployed.

## Purpose

Move stock between Delhi and Mumbai (and other configured branches) with a linked statutory GST invoice, without treating the movement as a retail POS customer sale.

## Workflows

| Workflow | Use when |
|---|---|
| **Inter-Branch Transfer** | Branch-to-branch stock movement with GST invoice + dispatch/receipt |
| **Inventory → Transfers (manual)** | Immediate internal relocation without statutory invoice |
| **POS** | Final customer sale at a branch |
| **PO / Goods Receipt** | Supplier inbound stock (new serials) |

## Operator path

1. Inventory → Inter-branch → New
2. Select source/destination branches, products, serials or quantities
3. Confirm issue (creates IBT + statutory invoice + draft transfer; reserves stock)
4. Dispatch (marks stock in transit; optional e-way reference — entered manually, not API-generated)
5. Receive at destination (stock becomes available)
6. Later: normal POS sale to final customer at receiving branch

## Important rules

- Inter-branch movement must **not** use POS at the source branch.
- Serials must be **available** at the source when issuing.
- PO/GR cannot receive serials that already exist in Desk inventory.
- Historical transactions (including INV-0767219 / POS-6739) are **not** auto-repaired by this feature.

## Reconciliation

Each `InterBranchTransaction` links:

- Statutory invoice (`statutory_invoice_id`)
- Inventory transfer (`inventory_transfer_id`)
- Reservation hold until dispatch (`inventory_reservation_id`)
- Line-level serials / quantities
- Dispatch + e-way reference fields (reference only unless future API integration is added)

## E-way bill

The application stores operator-entered e-way references only. It does **not** claim government e-way generation unless a verified API integration is added separately.
