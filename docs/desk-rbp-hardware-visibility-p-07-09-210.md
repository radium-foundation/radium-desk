# Restore RBP hardware visibility — RadiumDesk-P-07-09-210

**Date:** 2026-09-10  
**Prompt ID:** `RadiumDesk-P-07-09-210`  
**Mode:** Investigation + smallest Desk hardware-visibility fix. Box outbox gate updated at the ingest boundary so future RBP handoffs POST. IRN unchanged.

## Reason (verified)

Last new Box hardware ingest was **RDE318571** at **2026-09-09 22:12:17 IST**. At **23:06 IST** Box started allocating sequential **RBP*** product IDs instead of `RDE{orders.id}`.

Payment still:

1. Created Cashfree merchant orders as `RBP4` … `RBP29`
2. Created Desk `orders` via the Cashfree webhook (visible in search, serial null, product tags null)
3. Enqueued Box `desk_order_handoffs` (`pending`, attempts **0**)

The Box scheduled worker `desk:process-outbox` (every minute) uses `DeskSingleHandoffGate`, which refused any prefix other than `RDE`. RBP rows were skipped and never HTTP-POSTed. Desk `channel_ingest_attempts` for RBP = **0**.

Desk also classified `RBP` as non-hardware (`BusinessOrderId.hardware=false` and `shouldOpenRecord` required `RDE*`), so even a later POST would have created commerce without Hardware Fulfilment.

Hardware Fulfilment UI = existing HF rows + awaiting **RDE** without HF + windowed **RIN**. RBP Cashfree orders were invisible there.

All eight reported IDs fail for this same reason. Additional paid orders in the same gap: **RBP7**, **RBP22**.

## rdservice.in

Not the same bug. After the cutoff, Desk accepted **342** `rdservice_in` commerce rows (RD*). Retry schedule is **every five minutes**, not payment-instant. Last **RIN** hardware ingest: 2026-09-09 15:26 IST. No rdservice.in source code change in this ticket.

## Fix

- Desk: `RBP` is Box hardware (ingest opens HF, awaiting queue, spoke lookup, payment correlation).
- Box: worker delivers `RDE` and `RBP` physical handoffs; `RB`/`RD` service still skipped.
- Recovery: existing pending RBP handoffs through `DeskOutboxService::deliver()` after Desk overlay (idempotent HMAC ingest). No duplicate Cashfree orders. Serials not assigned.

## IRN isolation

WhiteBooks GENERATE / Get-IRN / provider / worker / auto-issue: **NO — Not performed.**
