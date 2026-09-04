# RD-FRESH-01 — POS-only release candidate (P-04-09-17)

**Project:** Radium Desk  
**Ledger:** RadiumDesk-P-04-09-17  
**Date:** 2026-09-04  
**Release branch:** `release/pos-4.0.66`  
**Release worktree:** `/Users/ravi/RadiumWebsites/radium-desk-pos-release`  
**Base:** `origin/main` `91c5117d` (v4.0.65)  
**Source POS SHA:** `b56072dd` on `feat/rd-fresh-01-inventory-pos`  
**Original feature worktree:** `/Users/ravi/RadiumWebsites/radium-desk` (left dirty; not deployed)

This is a **release candidate**, not a live production cutover.

## Scope included

- Inventory engine: products, variants, branches, serial/qty stock, transfers, adjustments, reservations, movements
- POS counter, sales, cancel/return, internal receipts
- Opening-import reader/preview/apply (creates missing SKUs; never creates branches)
- POS/inventory permissions in `RolePermissionSeeder`
- Opening-import migration `2026_09_04_200000_add_inventory_opening_import_foundation`
- Cashfree settlement: only exact `cash` posts to cash GL `1000`; Cashfree/UPI/Card use `1100`
- POS/inventory tests and POS docs already committed at `b56072dd`

## Scope excluded

- Uncommitted statutory / shipping / channel-ingest WIP in the feature worktree
- RadiumBox Read API
- Accountant reporting role / GST report permissions from the feature branch
- Feature-branch replacements of 4.0.65 statutory invoice issue routes
- Opening APPLY, SKU production writes, operator assignment, `deskd`

## Changelog / version

`CHANGELOG.md` entry **4.0.66 — Desk Inventory and POS**. Tag/deploy is a later gate.

## Opening workbook (not imported)

`/Users/ravi/Downloads/rd-fresh-01-opening-inventory-template.xlsx`  
SHA-256 `6048543e167f5ce7fb11265a4fa7f5c723dc26e6a37c89ca647280ebb81733f4`

## Next gates after this candidate

1. Owner-approved merge/tag of 4.0.66  
2. `deskd` from this clean branch (or `main` after merge) — not from the dirty feature worktree  
3. Permission seed via deploy  
4. Operator assignment to DELHI-RETAIL / MUMBAI  
5. Authorized opening APPLY  
6. Production sale verification  
