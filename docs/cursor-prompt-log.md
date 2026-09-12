# Cursor prompt log (Radium Desk)

Authoritative numbering for `RadiumDesk-P-*` IDs remains in `docs/cursor-prompt-ledger.md`. This log records prompts that explicitly requested `cursor-prompt-log.md`.

| ID | Date | Title | Notes |
|----|------|-------|-------|
| RadiumDesk-P-07-09-253 | 2026-09-11 | Establish safe QA environment for P-07-09-250 | Next unused after P-07-09-252. Disposable sqlite + loopback :8765. Browser QA PASS except live 429 (not observed locally). P-250 preserved uncommitted. No sale/commit/push/deploy. |
| RadiumDesk-P-07-09-254 | 2026-09-11 | Commit, push and deploy validated POS rapid-scan hardening | Next unused after P-07-09-253. After P-253 PASS. Commit/push P-250 only. Named-file overlay counter Blade to production. Cart-only verify. No sale/payment/invoice. |
| RadiumDesk-P-07-09-255 | 2026-09-11 | Recover remaining KVM8 RadiumBox sync failures RA3506965 / RA3506957 | Next unused after P-07-09-254. Companion rdservice.net lookup fix verified. Isolated backfill-sync per order. No code/deploy/bulk replay. |
| RadiumDesk-P-07-09-256 | 2026-09-12 | GLOBAL SEARCH SOURCE VERIFICATION (inspection-only gate) | Next unused after P-07-09-255. Read-only production spoke + Global Search tinker verification. Old Admin path off. No implementation. |
| RadiumDesk-P-07-09-258 | 2026-09-12 | OPTION B — Old Admin database discovery (read-only) | Next unused after P-07-09-257. Stream-inspected both SQL backups; KVM8 live DB probes; Global Search gap analysis. No restore/import/code/deploy. |
| RadiumDesk-P-07-09-259 | 2026-09-12 | Correct support phone number in customer-facing email templates | Next unused after P-07-09-258. Shared `support_contact` config + `SupportContactResolver` + master email layout footer. Service case complete and all notification/statutory mails. |
| RadiumDesk-P-07-09-266 | 2026-09-12 | Purchasing / Vendor / PO / Goods Receipt / Serialized Inventory foundation | Next unused after P-07-09-265. Purchasing workflow foundation on `feat/irn-foundation-phase-a`. Legacy vendor import mechanism + synthetic fixture only. No production import/deploy. |
| RadiumDesk-P-07-09-267 | 2026-09-12 | Purchasing Foundation — Pre-Production Remediation Gate | Next unused after P-07-09-266. PO searchable product selection; non-serialized receiving `goods_receipt_id` on movements; architecture review. Tests PASS. No deploy/production import. |
| RadiumDesk-P-07-09-268 | 2026-09-12 | STAGING migration + controlled legacy vendor import + full Purchasing UAT | Next unused after P-07-09-267. **STOPPED.** No documented isolated Desk staging host/DB/vhost. KVM8 has production `radium-desk` @ `desk.radiumbox.com` only; no `beta-desk`. `radiumbox_prod.supplier` count=21 verified read-only. No migration/import/deploy/UAT performed. Production untouched. |
| RadiumDesk-P-07-09-269 | 2026-09-12 | STAGING environment provisioning assessment (planning-only) | Next unused after P-07-09-268. Read-only infra inventory. No existing safe Desk staging verified. Recommended co-located KVM staging vhost+DB (beta pattern) with strict integration isolation, or separate VM. Owner action list + Gate 268 prerequisites. No provision/deploy/migrate/import. |
| RadiumDesk-P-07-09-270 | 2026-09-12 | Controlled production Purchasing readiness + migration safety review | Next unused after P-07-09-269. Read-only production verification. Migration SAFE (additive). Vendor import dry-run NOT AVAILABLE. Deploy blocked on main/tag + path-scoped migrate plan. NOT READY for controlled rollout until execution gate. No production writes. |

Do not renumber or overwrite earlier rows. Append only.
