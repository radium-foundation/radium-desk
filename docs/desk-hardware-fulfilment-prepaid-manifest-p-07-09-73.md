# Prepaid courier display + manifest print verification — RadiumDesk-P-07-09-73

**Date:** 2026-09-08  
**Prompt ID:** `RadiumDesk-P-07-09-73`  
**Type:** Targeted correction. No production write. No deploy. No RDE318421 mutation.

Ledger: `docs/cursor-prompt-ledger.md`. Last used ID: **P-07-09-72**. This ticket: **P-07-09-73**.

Classification: **VERIFIED** / **INFERRED** / **UNKNOWN**.

---

## Verdict

Current Radium Desk hardware orders are prepaid. The courier-options screenshot that showed `COD yes` was an operator-facing mapping error, not an enabled COD product.

Serviceability already sent `cod=0`. Shiprocket’s row `cod` field is courier capability. The previous UI printed that capability as if the customer order were COD.

The operator now sees **Prepaid**. COD ordering remains unavailable.

Manifest generation remains `POST /manifests/generate` with a `shipment_id` array. Official print/download remains **UNKNOWN**. No invented print endpoint was added. Download is shown only when generate already returned a URL.

RDE318421 was **not** touched.

---

## Git (before modify)

| Item | Value | Class |
|------|-------|-------|
| Repository | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` | VERIFIED |
| Remote | `git@github.com:radium-foundation/radium-desk.git` | VERIFIED |
| Branch | `main` (ahead of `origin/main` by P-07-09-71 and P-07-09-72) | VERIFIED |
| Before SHA | `1af8733211310c83361eef5b4a4f544bdb38d988` | VERIFIED |
| `origin/main` | `1575ed35c4905c7dfd24377321d85d8d497dfc87` | VERIFIED |
| Worktree | Unrelated statutory docs dirty; not included | VERIFIED |

---

## Part 1 — COD / prepaid root cause

| Hypothesis | Finding | Class |
|------------|---------|-------|
| A. App sent `cod=1` | No. Courier options already constructed `ShiprocketCourierOptionsRequest` with `cod: 0`. Create/adhoc already used `paymentMethod: 'Prepaid'`. | VERIFIED |
| B. Shiprocket `cod` is courier capability | Gateway maps row `cod` / `is_cod` / `cod_available` onto `ShiprocketCourierOption::$codAvailable`. Official serviceability is requested with a separate query `cod` for the order. | VERIFIED |
| C. UI treated capability as customer payment mode | Earlier courier cards printed `COD yes` from `cod_available`. That is capability, not order collection mode. | VERIFIED |
| D. Other mapping | `commerce_orders.payment_method` is the instrument (UPI/card/etc), not prepaid vs COD. Resolver does not read it. | VERIFIED |

Payment mode source for Shiprocket is now `HardwareShipmentCollectionModeResolver`:

- current hardware workflow → `prepaid`
- serviceability query → `cod=0`
- create/adhoc → `payment_method=Prepaid`
- operator label → `Prepaid`

COD remains a reserved enum case. It is not enabled (`isEnabledForHardware()` is true only for Prepaid). Operators cannot POST `cod`, `collection_mode`, or `payment_method`.

Provider capability is **not** shown as `COD yes` or `COD supported`. Because Desk does not offer COD, that extra line would still mislead.

---

## Part 2 — Manifest contract

| Question | Conclusion | Class |
|----------|------------|-------|
| Generate endpoint | Official helpsheet + Desk gateway: `POST /v1/external/manifests/generate` | VERIFIED |
| Generate body | `{ "shipment_id": [<provider shipment id>] }` — array, batch-capable | VERIFIED (official Postman + Desk) |
| Generate prerequisites | AWB assigned and pickup requested | VERIFIED (helpsheet sequence + SimWorkflow note) |
| Generate success HTTP | 200 observed in Postman even when generation failed | VERIFIED (Postman example) |
| Generate success body | Official Postman example returned `{ "message": "Manifest not generated", "check_ids": [...] }` with **no URL** | VERIFIED |
| Generate URL/id fields | Desk accepts `manifest_url` / `manifest_id` or `payload.*` **if present**. Official success schema with those keys was not found. | INFERRED persistence; UNKNOWN official field names |
| Synchronous? | Helpsheet lists generate then a separate print call, which implies generate may not return the PDF | INFERRED |
| Repeat generate | Desk is locally idempotent: second generate does not call the provider when URL or id is already stored. Provider-side duplicate records | UNKNOWN |
| Print endpoint | Helpsheet: `POST /v1/external/manifests/print` “You will get PDF url” | VERIFIED that the path is named |
| Print request body | Not published on the official helpsheet. SDK uses a different path `POST /orders/print/manifest` with `order_ids`. | UNKNOWN |
| Print response field | Helpsheet says “PDF url”. Field name not published. | UNKNOWN |
| Official UI print | Shiprocket panel can print manifests. That is not an API contract. | INFERRED |

**Manifest generation verified; print/download mechanism remains UNKNOWN.**

Implementation rule applied: do **not** add `/manifests/print` or `/orders/print/manifest`. Do **not** invent a PDF.

Existing operator action stays:

1. **Generate Manifest** after AWB + pickup.
2. Persist provider `manifest_url` / `manifest_id` only when returned.
3. Show **Download Manifest** only when a URL was persisted.
4. If generate returns only `message: Manifest not generated`, treat as rejected. No fake file.

---

## Operator UI

Courier card and confirm-shipment block now show **Payment mode: Prepaid**.

Courier dropdown appends ` · Prepaid` to each returned option. Recommendation, name, id, rate, ETD, and provider type/mode are unchanged and still shown only when returned.

No COD selector. No `COD yes`.

---

## Tests / lint

Focused PHPUnit:

- collection-mode unit
- courier workflow
- operational workflow
- show readiness
- shipment UI
- HTTP gateway

Pint on dirty PHP for this ticket.

No live Shiprocket write calls. Tests use Fake / `Http::fake`.

---

## Not performed

- Production deploy: **NO — Not performed.**
- RDE318421 parcel / courier / shipment / AWB / label / manifest / pickup: **NO — Not performed.**
- Live provider write calls: **NO — Not performed.**
- COD ordering: **NO — Not performed.**
- Manifest print/download endpoint: **NO — Not performed.** Contract remains UNKNOWN.
- Invented PDF: **NO — Not performed.**
- Channel ID invented/copied: **NO — Not performed.**
- Payment / Cashfree / historical order changes: **NO — Not performed.**
- Credentials change: **NO — Not performed.**

---

## Remaining risks

1. Live generate may succeed without returning a URL; operators then have a generated-at-provider manifest they cannot download from Desk until print is verified.
2. Live create/adhoc may still require a channel ID.
3. Official print body/path conflict is unresolved.
4. RDE318421 still needs an authorized parcel snapshot before courier options.
