# Serial popover above Package Dimensions — RadiumDesk-P-07-09-136

**Date:** 2026-09-09  
**Prompt ID:** `RadiumDesk-P-07-09-136`  
**Mode:** UI layering/copy source only. No serial/parcel/invoice/shipment mutation.

Ledger last used: **P-07-09-135**. This ticket: **P-07-09-136**.

---

## Cause

The allocated-serial panel was already portaled to `document.body` (so modal `overflow: hidden` would not clip it), but its CSS `z-index` was **1090**.

Workspace layering:

| Layer | z-index |
|-------|--------:|
| Workspace modal backdrop | 1090 |
| Workspace modal (`[data-workspace-modal-host]`) | 1095 |
| Serial panel (P-07-09-135) | 1090 |
| To-Do stacked modal | 1100 |

The popover therefore painted **under** Enter Package Dimensions. Operators could still see the compact trigger `10532347 +9` and copy that label; they could not reach **Copy All**.

`data-copy-value` already contained the full newline-joined allocated serial array from `HardwareAllocatedSerialDisplay::copyValue()`. It never used the compact label. No serial records were wrong.

## Fix

- Raise `.hardware-serial-summary__panel` to **1105** (one step above the workspace modal, without changing the modal/backdrop scale).
- Keep the body portal.
- Escape while the popover is open closes the popover only (capture + stopPropagation) so Package Dimensions stays open.

Compact presentation `10532347 +9` is unchanged. Qty 1 still uses the single copyable serial.

---

## Tests / build

- PHPUnit HardwareFulfilment feature+unit: 307 passed, 1 skipped
- Focused serial display/view/parcel tests: passed
- Vitest `hardware-action-dialog`: 19 passed (modal layering, Copy All ≠ compact, Escape does not dismiss modal)
- Pint `--dirty`: passed
- Vite: `app-DF7xny3l.js`, `app-CNsC_50T.css`

---

## Git

Before SHA: `4cb761ed6be9c5d7e6d718314cebf2b880699bd5`  
Unrelated dirty shipping/statutory files: **not included**.  
MakeLinkIT / Gitea: **NO — Not performed.**
