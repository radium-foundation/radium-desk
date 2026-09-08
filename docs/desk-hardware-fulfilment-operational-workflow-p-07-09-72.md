# Hardware fulfilment operational Shiprocket workflow — RadiumDesk-P-07-09-72

**Date:** 2026-09-08  
**Prompt ID:** `RadiumDesk-P-07-09-72`  
**Type:** Implementation and tests only. No production write. No deploy. No RDE318421 mutation.

Ledger: `docs/cursor-prompt-ledger.md`. Last used ID: **P-07-09-71**. This ticket: **P-07-09-72**.

Classification: **VERIFIED** / **INFERRED** / **UNKNOWN**.

---

## Verdict

The existing `/inventory/hardware-fulfilments` workspace now covers the manual operator path:

Get Courier Options → dropdown selection → Create Shipment → Assign AWB → Generate/Print Label → package photos → Request Pickup → Generate Manifest → Mark Ready for Pickup.

No automatic create, courier, AWB, label, pickup, or manifest. Shiprocket recommendation remains display-only. Happy-path fulfilment states were **not** expanded.

RDE318421 was **not** touched.

---

## Git (before modify)

| Item | Value | Class |
|------|-------|-------|
| Repository | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` | VERIFIED |
| Remote | `git@github.com:radium-foundation/radium-desk.git` | VERIFIED |
| Branch | `main` (ahead of `origin/main` by P-07-09-71 report) | VERIFIED |
| Before SHA | `6ec82a297b5ea52006b8bb60a65b8543bd152776` | VERIFIED |
| Worktree | Unrelated statutory docs dirty; not included | VERIFIED |

---

## Shiprocket API (verified before implement)

| Capability | Contract | Class |
|------------|----------|-------|
| Auth | `POST /auth/login` email/password | VERIFIED (Desk + P-07-09-71) |
| Serviceability | `GET /courier/serviceability/` | VERIFIED |
| Create | `POST /orders/create/adhoc` | VERIFIED |
| Courier on create | Not in Desk mapper / Admin `GenrateOrder` | VERIFIED |
| AWB | `POST /courier/assign/awb` with `shipment_id` + optional `courier_id` | VERIFIED |
| Label | Official Postman/helpsheet: `POST /courier/generate/label` with `shipment_id` **array**; requires AWB; returns `label_url` PDF | VERIFIED |
| Previous Desk label call | `GET` + scalar id. Replaced to match official POST | VERIFIED (bug vs official) |
| Pickup | `POST /courier/generate/pickup` with `shipment_id` array; requires AWB; explicit | VERIFIED (already in gateway) |
| Manifest generate | Official: `POST /manifests/generate` with `shipment_id` array; AWB + pickup required; can be multi-shipment | VERIFIED (Postman/helpsheet) |
| Manifest print | Helpsheet `POST /manifests/print` vs SDK `POST /orders/print/manifest` | UNKNOWN body/path conflict — **not implemented** |
| Tracking | `GET /courier/track/awb/{awb}` and `GET /courier/track` | VERIFIED present, not newly wired to UI |
| Cancel | `POST /orders/cancel` | VERIFIED present, not newly wired to UI |
| Channel ID on auth/serviceability | Not required | VERIFIED |
| Channel ID on live create/adhoc | Still omitted when empty | UNKNOWN |

Desk does not invent a channel id. Create payload still omits empty `channel_id`.

---

## Courier / service fields

Parsed from the provider row when present:

| Field | Source keys | Class |
|-------|-------------|-------|
| Courier ID | `courier_company_id` / `courier_id` / `id` | VERIFIED |
| Courier name | `courier_name` / `name` | VERIFIED |
| Rate | `freight_charge` / `rate` | VERIFIED |
| ETD | `etd` / `estimated_delivery_days` / `estimated_delivery` | VERIFIED |
| Recommendation | `recommended_courier_company_id` / `shiprocket_recommended_courier_id` / row flags | VERIFIED parse; live key varies |
| Type | `courier_type` | INFERRED optional; displayed only if returned |
| Mode | `mode` / `shipping_mode` | INFERRED optional; displayed only if returned |

There is no verified independent “Prime Air” field. The UI shows the provider’s returned name plus type/mode when supplied. One dropdown. No local ranking.

---

## State-machine mapping (no new happy-path states)

| Operational concept | Mapping |
|---------------------|---------|
| Ready for shipment | `invoice_issued` + `inspect()` local gates |
| Courier options / selection | Fulfilment quote columns, not a state |
| Shipment created | `shipment_created` |
| AWB assigned | `awb_assigned` |
| Label available | `shipments.label_url` |
| Label pasted | `package_label_applied` evidence |
| Manifest | `shipments.manifest_url` / `manifest_id` |
| Pickup requested | `shipments.pickup_requested_at` |
| Ready for pickup | `hardware_fulfilments.ready_for_pickup_at` (timestamp, not an enum) |
| Picked up / in transit | existing `shipped` |

`SHIPPED` still requires AWB evidence. Operator `markShipped` (user actor) now also requires the label-applied photo. Isolated/system actors unchanged.

---

## Evidence / media

No generic media library existed for fulfilment photos. Payment evidence is Cashfree identifiers, not images.

Added typed table `hardware_fulfilment_package_evidences`:

- `package_before_label` — allowed before shipment; not proof of labelling
- `package_label_applied` — only after AWB

Stored on the private `local` disk. View is authenticated. One row per kind (replace allowed).

---

## Tests / lint

Focused: operational workflow + courier + show + shipment UI + HTTP gateway = **45 passed**.

Broader hardware fulfilment + shipping + unit: **208 passed**, 1 pre-existing failure:

`HardwareFulfilmentP0M2BranchDerivationTest::test_unset_fulfilment_can_search_serials_filtered_by_physical_branch` expects `Delhi / DELHI-RETAIL` copy that the P-07-09-69 show page no longer renders. Not caused by this ticket.

Pint on dirty PHP: clean (controller imports ordered).

---

## Not performed

- Production deploy: **NO — Not performed.**
- RDE318421 parcel / courier / shipment / AWB / label / manifest / pickup: **NO — Not performed.**
- Live provider write calls: **NO — Not performed.** Fake/HTTP-fake only.
- New `/inventory/shipments`: **NO — Not performed.**
- New `hardware.fulfilment.ship`: **NO — Not performed.**
- Manifest print endpoint: **NO — Not performed.** Path conflict remains UNKNOWN.
- Channel ID invented/copied: **NO — Not performed.**
- Happy-path enum expansion: **NO — Not performed.**

---

## Remaining risks

1. Live create/adhoc may still require a channel ID.
2. Manifest **print** is not implemented; generate persists URL/id only if Shiprocket returns them.
3. Official label/manifest/pickup were not called live in this ticket.
4. RDE318421 still needs an authorized parcel snapshot before courier options.
