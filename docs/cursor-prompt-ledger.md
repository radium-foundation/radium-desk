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
| RadiumDesk-P-31-08-15 | 2026-08-31 | Recognize RA{n} RDService.net order namespace on Desk | Supplied chat id was P-31-08-13; ledger already used 13 (v4.0.64) and 14. `docs/rdservice-ra-namespace-compatibility.md`. Does not activate RDService. Does not rename historical RD ids. |

Do not renumber or overwrite earlier rows. Append only.
