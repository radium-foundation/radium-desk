# Central Wallet handover addendum — P-18-45 (2026-11-18)

**Appends to:** workspace `RADIUM_CENTRAL_WALLET_PROJECT_HANDOVER.md` (do not replace prior rows).  
**Authoritative closure report:** [`docs/central-wallet-final-closure-audit-p-18-45.md`](central-wallet-final-closure-audit-p-18-45.md)

## Snapshot (read-only, 2026-11-18 IST)

| Item | Value |
|------|--------|
| Desk prod | **v4.1.91** / **`966567336`** |
| Desk-first | **enabled**; allowlist **rdservice.in, radiumbox.com, rdservice.net** |
| RN357 refund **479** | **closed**, **CW:152**, ledger **#152** |
| RN377 refund **494** | **closed**, **CW:151**, ledger **#151** |
| Box historical credits | Desk ledger **#79–#84**, total **₹3,914** |
| P-18-24 HTTP ACL | **Not deployed** (design only) |
| Closure verdict | **PARTIALLY CLOSED** |

## Operator note

Spoke production release tags remain **UNKNOWN** for radiumbox.com and rdservice.in (overlay deploys without `release.json`). rdservice.net server git **`d9aa03b`** with **`refund_destination=false`** and **`api_base_url=http://desk-uat.radiumbox.com`** — treat RDNET CW integration as **OPEN** until Owner-approved alignment.

**Ledger:** `RadiumDesk-P-18-45`