# Shiprocket combined-address 190 normalizer — RadiumDesk-P-07-09-139

**Date:** 2026-09-09  
**Prompt ID:** `RadiumDesk-P-07-09-139`  
**Mode:** Shiprocket create/adhoc payload only. No stored address write. No shipment create/retry. No AWB/invoice/serial/parcel mutation.

Ledger last used: **P-07-09-138**. This ticket: **P-07-09-139**.  
Before SHA: `bab4b61c2e34e417017b046321a60628ed7d13a4`

---

## Root cause

Shiprocket `POST /orders/create/adhoc` rejects combined Address 1 + Address 2 above **190 UTF-8 characters**. RDE318516 / HF15 and RDE318517 / HF16 send 155 + 44 = **199**. Desk stored the original checkout lines unchanged; the provider payload had no combined-length gate.

## Implementation

`ShiprocketAdhocAddressNormalizer` runs only inside `ShiprocketCreateOrderRequest::toAdhocPayload()`.

1. Copy Address 1 / Address 2 / structured city, state, pincode, country. Source DTO properties are not written.
2. Combined length uses UTF-8 `mb_strlen`, matching the existing city helper. `strlen` is not used.
3. Combined ≤190: return the original lines byte-for-byte.
4. Combined >190: strip a **trailing** suffix that is the structured state and/or pincode already sent as dedicated fields (including spaced PIN `562 112`). Do not search-replace inside institution, road, locality, landmark, district, or city.
5. Never `substr` / blind truncate. Never move text between Address 1 and Address 2 to dodge the combined cap.
6. If still >190: `ValidationException` on `shipping` before HTTP. Operator message asks for address review.

`shipping_is_billing=false` normalizes billing and shipping pairs independently with each pair’s own state/pincode.

This commit also persists the already-tested `billing_city` max-30 helper on the same DTO so named-file overlay does not regress production city clamp.

## RDE318516 expected payload

| Field | Stored (unchanged) | Adhoc payload |
|-------|--------------------|---------------|
| Address 1 | 155 chars including `, Karnataka - 562 112` | trailing state/PIN removed (134) |
| Address 2 | `BANGALORE KANAKAPURA HIGHWAY, NEAR HAROHALLI` (44) | unchanged |
| Combined | 199 | **178** |
| state | Karnataka | Karnataka |
| pincode | 562112 | 562112 |
| country | India | India |
| city | Ramanagara | Ramanagara |

Meaningful delivery text retained: institute, Deverakaggalahalli, Kanakapura Road, Bengaluru South District, highway, HAROHALLI.

RDE318517 uses the same checkout address; the same payload path applies.

## Files

- `app/Services/Shipping/Data/ShiprocketAdhocAddressNormalizer.php`
- `app/Services/Shipping/ShiprocketAdhocAddressLimitException.php`
- `app/Services/Shipping/Data/ShiprocketCreateOrderRequest.php`
- unit + gateway tests
- this report + ledger row

## Not performed in this ticket

- Create Shipment / Shiprocket write
- Retry or mutate `shipments.id=10`
- Address / invoice / serial / parcel / AWB writes
- Migrations / `.env` / global outbox
- Full `deskd`
- MakeLinkIT
