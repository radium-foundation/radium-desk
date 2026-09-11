# Walk-in POS production flow (P-07-09-163)

## Admin → Desk → Required

| Area | Old Admin POS | Current Desk (before) | Required Desk (this change) | Evidence |
|---|---|---|---|---|
| Customer | `users` + `users_address` snapshot | `inventory_customers` by phone | Finance Party + `inventory_customers` bridge | VERIFIED |
| B2C | Minimal name/phone | Name/phone/email | Same + party create on complete | VERIFIED |
| B2B | GSTIN on address | Optional GSTIN field | Required GSTIN, billing, state, PIN | VERIFIED |
| GST registrations | Per address | Single gstin on customer | Multiple via `finance_party_gst_registrations` | VERIFIED |
| Place of supply | Manual / GSTIN prefix habits | Manual dropdown | Walk-in handover = seller branch state; delivery override | VERIFIED |
| Sale snapshot | Order snapshot | Partial sale columns | Extended immutable snapshot on `inventory_sales` | VERIFIED |
| Statutory invoice | Admin series | Manual Finance Hub issue | Auto issue via `PosWalkInCompletionService` | VERIFIED |
| GST split | Admin calc | Commerce only | POS `InventorySale` mint uses `GstSplitService` | VERIFIED |
| PDF serials | Admin invoice | Hardware fulfilment only | POS sale serials on statutory PDF | VERIFIED |
| Email | Admin mail | Absent | `StatutoryInvoiceMail` + dispatch log | VERIFIED |
| WhatsApp | Unknown | Service-case only | `wa.me` share link (no API) | VERIFIED |
| IRN | WhiteBooks | Outbox/skip | Unchanged — queues when B2B eligible | VERIFIED |
| Idempotency | Unknown | Page-load UUID | Session-persisted checkout key | VERIFIED |
| Admin customer import | 1,014 POS users | Not imported | **Not performed** — separate owner gate | VERIFIED |

## Config

- `statutory_invoices.walk_in_auto_issue_statutory` (default `true`)
- Legacy `auto_issue_on_pos_complete` remains fail-closed and unused for walk-in

## Not in scope

- Offline/PWA/IndexedDB
- Admin bulk customer import
- Production deployment (owner gate)
