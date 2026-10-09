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
- Historical Delhi→Mumbai POS movements (including INV-0767219 / POS-6739) require **legacy reconciliation** — see below.

## Legacy reconciliation (historical POS movements)

Historical Delhi→Mumbai stock was incorrectly completed through POS. Legacy reconciliation:

- Reuses the **existing** statutory invoice and IRN (no `StatutoryInvoiceService::mint()`)
- Preserves the existing POS sale and finance journal (no `cancelSale()` / no journal reversal)
- Creates a completed `InterBranchTransaction` + inventory transfer
- Moves serials: sold @ Delhi → in transit → available @ Mumbai
- Does **not** generate Desk e-way bills or invoke PO/GR

**Admin command (not POS/GR UI):**

```bash
php artisan inventory:reconcile-legacy-inter-branch --sale=39 --dry-run
php artisan inventory:reconcile-legacy-inter-branch --discover
```

Production execution requires explicit owner approval after dry-run and accounting review.

## Reconciliation

Each `InterBranchTransaction` links:

- Statutory invoice (`statutory_invoice_id`)
- Inventory transfer (`inventory_transfer_id`)
- Reservation hold until dispatch (`inventory_reservation_id`)
- Line-level serials / quantities
- Dispatch + e-way reference fields (reference only unless future API integration is added)

## E-way bill

The application stores operator-entered e-way references only. It does **not** claim government e-way generation unless a verified API integration is added separately.
