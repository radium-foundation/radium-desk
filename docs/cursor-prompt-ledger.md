# Cursor prompt ledger (Radium Desk)

In-repo sequence record for `RadiumDesk-P-*` prompts. No prior ledger file was present in this repository or the GitHub remote; entries below are **only** tickets verified from committed/untracked docs already in this worktree. Gaps are not invented.

| ID | Date | Title | Repo path / notes |
|----|------|-------|-------------------|
| RadiumDesk-P-30-08-01 | 2026-08-30 | RDService Desk invoice flow investigation | `docs/rdservice-desk-invoice-flow-investigation.md` (untracked at inspect) |
| RadiumDesk-P-30-08-08 | 2026-08-30 | RDService.net Desk order API integration | `docs/rdservice-desk-order-api-integration.md` |
| RadiumDesk-P-30-08-10 | 2026-08-30 | RDService.net production activation readiness | `docs/rdservice-production-activation-readiness.md` (untracked at inspect) |
| RadiumDesk-P-31-08-02 | 2026-08-31 | RDService-first enrichment preference | `docs/rdservice-first-enrichment-preference.md` (untracked at inspect) |
| RadiumDesk-P-31-08-09 | 2026-08-31 | Desk independence from Admin `GET /api/search/order` | `docs/desk-admin-order-independence.md` · commit `43e773dd` |
| RadiumDesk-P-31-08-10 | 2026-08-31 | Validate Desk Admin-independence implementation | This prompt. Next verified sequence after P-31-08-09. Does not activate RDService. Does not retire Admin. |
| RadiumDesk-P-31-08-11 | 2026-08-31 | Integrate Desk Admin-order-independence onto main | Source/git gate only. Fast-forward to `main`. Does not activate RDService. Does not deploy. Does not retire Admin. |
| RadiumDesk-P-31-08-12 | 2026-08-31 | Prepare Desk production release/deployment gate | Source/changelog only. Do not deskd. Do not tag unless documented+approved. Do not activate RDService. |
| RadiumDesk-P-31-08-13 | 2026-08-31 | Release and deploy v4.0.64 | Annotated tag `v4.0.64` on `0d734f85`. `deskd` to KVM. Do not activate RDService. |
| RadiumDesk-P-31-08-14 | 2026-08-31 | Investigate RDService.net successful orders and invoice generation | Read-only. `docs/rdservice-successful-orders-invoice-path-investigation.md`. Do not activate RDService. Do not generate invoice. |
| RadiumDesk-P-01-09-01 | 2026-09-01 | BonVoice MariaDB 1020 persist race | Recorded on `fix/bonvoice-call-event-1020-race`. Not this inventory branch. |
| RadiumDesk-P-01-09-02 | 2026-09-01 | BonVoice 1020 fix review | Recorded on `fix/bonvoice-call-event-1020-race`. Not reused here. |
| RadiumDesk-P-01-09-03 | 2026-09-01 | Inventory + POS operational continuation | `feat/rd-fresh-01-inventory-pos`. Builds on `006e5bf3`. User prompt was labelled P-01-09-02; next free ID is P-01-09-03. `docs/rd-fresh-01-inventory-pos-foundation.md`. Do not deploy. Do not migrate Admin stock. |
| RadiumDesk-P-01-09-04 | 2026-09-01 | Inventory + POS controlled operational test | `feat/rd-fresh-01-inventory-pos`. HTTP/operator workflow + permission tests. MySQL concurrency skipped (no mysqld). Do not deploy. Do not migrate Admin stock. |
| RadiumDesk-P-01-09-05 | 2026-09-01 | Inventory + POS MySQL concurrency + browser QA | `feat/rd-fresh-01-inventory-pos`. Two-process InnoDB harness ready; local mysqld absent → UNKNOWN/BLOCKER. Chrome operator QA on disposable sqlite. Stock-in variant select fix. Do not deploy. |
| RadiumDesk-P-01-09-06 | 2026-09-01 | RadiumBox inventory migration assessment | Read-only SQL against `radiumbox_prod`. `docs/rd-fresh-01-radiumbox-inventory-investigation.md`. Do not import. Do not deploy. Do not modify RadiumBox / radiumbox_prod / Desk production. |
| RadiumDesk-P-01-09-07 | 2026-09-01 | RadiumBox available-inventory Excel export | Read-only. Workbook generated locally, not committed. Do not import. Do not deploy. Do not modify radiumbox_prod / Desk production. |
| RadiumDesk-P-01-09-08 | 2026-09-01 | Opening-inventory field matrix + empty Excel template | Read-only Admin/POS field reference. `docs/rd-fresh-01-inventory-opening-field-matrix.md`. Empty xlsx gitignored under `storage/app/private/inventory-opening/`. Do not migrate stock. Do not deploy. Do not modify Admin / radiumbox_prod / Desk production. |
| RadiumDesk-P-01-09-09 | 2026-09-01 | Inventory + POS MariaDB InnoDB concurrency | `feat/rd-fresh-01-inventory-pos`. Disposable `radium_desk_inventory_pos_test` on loopback MariaDB 11.8.8:33118 only. Five two-connection cases VERIFIED after gap-lock fix. Do not deploy. Opening workbook untouched. |
| RadiumDesk-P-01-09-10 | 2026-09-01 | POS / Finance final gap audit vs verified Admin/POS | `feat/rd-fresh-01-inventory-pos`. Compare Desk POS+Finance to documented Admin behaviour only. Do not import inventory/serials. Do not modify radiumbox_prod or production. Do not implement GST e-invoice/TCS/wallet/shipping/coupons. Do not deploy. |
| RadiumDesk-P-01-09-11 | 2026-09-01 | Inventory + POS final operator QA | `feat/rd-fresh-01-inventory-pos`. Disposable local UI only. Do not import opening inventory. Do not modify radiumbox_prod. Do not deploy. |
| RadiumDesk-P-01-09-12 | 2026-09-01 | POS cancel/return finance reverse (non-inventory Day-1) | `feat/rd-fresh-01-inventory-pos`. Highest remaining non-inventory blocker: posted `pos_sale` journals were not reversed on cancel/return. Use existing Cash Book reversing-entry pattern. Not a GST credit note / IRN. Do not import inventory. Do not modify radiumbox_prod or production. Do not deploy. |
| RadiumDesk-P-01-09-13 | 2026-09-01 | Inventory + POS final non-inventory Day-1 blocker audit | `feat/rd-fresh-01-inventory-pos`. Permissions, finance fail-closed, serial race vs cancel/return/transfer, GSTIN/HSN, reservation safety, additive migrations. Do not import inventory. Do not modify radiumbox_prod / Admin / production. Do not deploy. |
| RadiumDesk-P-01-09-14 | 2026-09-01 | Central finance + statutory invoice architecture | User prompt labelled P-01-09-13; that ID was already used. Docs only. `docs/rd-central-finance-invoice-architecture.md`. Do not implement engine. Do not migrate. Do not mint invoices. Do not modify production / Admin / rdservice.in / other websites. Legal series format UNKNOWN (CA). |

Do not renumber or overwrite earlier rows. Append only.
