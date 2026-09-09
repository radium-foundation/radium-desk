# Finance Party Master foundation — RadiumDesk-P-07-09-160

**Date:** 2026-09-09  
**Prompt ID:** `RadiumDesk-P-07-09-160`  
**Mode:** Additive schema + Finance UI. Do **not** migrate old Admin. Do **not** change invoices, POS, or GL. Do **not** deploy.

## Scope

Desk now has a reusable Finance Party master. One legal entity can be a customer, a vendor, or both. Identity, addresses, contacts, and GST registrations are shared. Commercial terms live on the role row. Vendor bank details are encrypted and permission-gated.

POS `inventory_customers` is unchanged and is not the Party master. Future POS rows may store an optional `finance_parties.id` without live-joining issued invoices.

## Schema

| Table | Purpose |
| --- | --- |
| `finance_parties` | Legal identity, code `PTY-{id}`, phone/email (indexed, not unique) |
| `finance_party_roles` | `customer` / `vendor`; payment terms, credit days/limit, vendor code |
| `finance_party_addresses` | Multiple addresses; default billing/shipping |
| `finance_party_contacts` | Multiple contacts; one primary |
| `finance_party_gst_registrations` | Unique per party+GSTIN, not globally unique |
| `finance_party_vendor_bank_accounts` | Encrypted account number + IFSC; `last_four` for list display |
| `finance_party_legacy_identities` | Empty mapping table for a later Admin import gate |

## Permissions

| Permission | Grant |
| --- | --- |
| `finance.parties.view` | Follows `finance.view` expansion |
| `finance.parties.manage` | admin, operations_admin, superadmin |
| `finance.parties.bank.view` | admin, superadmin only |

## Out of scope

- Old Admin customer/vendor/GST/bank/invoice/PO import
- Invoice, PO, PI, IRN, or document snapshot wiring
- AR/AP or GL posting
- Production migrate/deploy
