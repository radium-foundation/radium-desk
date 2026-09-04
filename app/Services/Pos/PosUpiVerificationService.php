<?php

namespace App\Services\Pos;

use App\Enums\PosPaymentIntentStatus;
use App\Models\InventoryReservation;
use App\Models\InventorySale;
use App\Models\PosPaymentIntent;
use App\Models\User;
use App\Services\Inventory\PosSaleService;
use App\Support\Pos\PosUpiUriBuilder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PosUpiVerificationService
{
    public function __construct(
        private readonly PosSaleService $sales,
        private readonly PosUpiIntentService $intents,
    ) {}

    public static function normalizeUtr(string $utr): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', trim($utr)));
    }

    /**
     * Confirm a pending UPI intent after a human bank check. completeSale runs
     * inside this transaction so a finance/stock failure leaves the intent pending.
     */
    public function confirm(
        PosPaymentIntent $intent,
        User $actor,
        string $utr,
        bool $bankChecked,
        float|string|null $confirmedAmount = null,
    ): InventorySale {
        $utr = self::normalizeUtr($utr);
        if ($utr === '') {
            throw ValidationException::withMessages([
                'utr' => 'Enter the UTR / bank reference.',
            ]);
        }

        if (! $bankChecked) {
            throw ValidationException::withMessages([
                'bank_checked' => 'Confirm that you checked the live bank account for this credit.',
            ]);
        }

        $intent = $this->intents->refreshExpiry($intent, $actor);

        try {
            return DB::transaction(function () use ($intent, $actor, $utr, $confirmedAmount): InventorySale {
                $locked = PosPaymentIntent::query()->lockForUpdate()->findOrFail($intent->id);

                if ($locked->status === PosPaymentIntentStatus::Completed && $locked->sale_id !== null) {
                    $sale = InventorySale::query()->findOrFail($locked->sale_id);

                    return $sale->fresh(['lines.product', 'serials.serial', 'customer', 'branch', 'upiIntent']) ?? $sale;
                }

                if ($locked->status !== PosPaymentIntentStatus::Pending) {
                    throw ValidationException::withMessages([
                        'intent' => $locked->abandon_reason === 'Expired'
                            ? 'This UPI payment expired before it was verified.'
                            : 'This UPI payment is no longer pending.',
                    ]);
                }

                if ($locked->expires_at !== null && $locked->expires_at->isPast()) {
                    throw ValidationException::withMessages([
                        'intent' => 'This UPI payment expired before it was verified.',
                    ]);
                }

                $duplicate = PosPaymentIntent::query()
                    ->where('utr', $utr)
                    ->where('id', '!=', $locked->id)
                    ->exists();
                if ($duplicate) {
                    throw ValidationException::withMessages([
                        'utr' => 'That UTR is already recorded on another verified UPI payment.',
                    ]);
                }

                $payload = $locked->cart_payload ?? [];
                $lines = $payload['lines'] ?? [];
                $headerDiscount = (float) ($payload['discount'] ?? 0);
                $quote = $this->sales->quoteTotals($lines, $headerDiscount);
                $quoted = PosUpiUriBuilder::formatAmount($quote['total']);
                $expected = PosUpiUriBuilder::formatAmount($locked->amount);

                if ($quoted !== $expected) {
                    throw ValidationException::withMessages([
                        'amount' => 'The cart total no longer matches this UPI intent. Abandon it and create a new payment.',
                    ]);
                }

                if ($confirmedAmount !== null && $confirmedAmount !== '') {
                    $confirmed = PosUpiUriBuilder::formatAmount($confirmedAmount);
                    if ($confirmed !== $expected) {
                        throw ValidationException::withMessages([
                            'confirmed_amount' => 'The bank credit amount must match the POS sale total. Do not complete a mismatch.',
                        ]);
                    }
                }

                $reservation = $locked->reservation_id !== null
                    ? InventoryReservation::query()->find($locked->reservation_id)
                    : null;

                $sale = $this->sales->completeSale(
                    branch: $locked->branch,
                    customer: $payload['customer'] ?? [
                        'name' => $locked->customer_name,
                        'phone' => $locked->customer_phone,
                    ],
                    lines: $lines,
                    paymentMethod: 'UPI',
                    actor: $actor,
                    headerDiscount: $headerDiscount,
                    paymentReference: $utr,
                    notes: $payload['notes'] ?? null,
                    reservation: $reservation,
                    idempotencyKey: $locked->sale_idempotency_key,
                );

                if (PosUpiUriBuilder::formatAmount($sale->total) !== $expected) {
                    throw ValidationException::withMessages([
                        'amount' => 'The completed sale total does not match this UPI intent.',
                    ]);
                }

                $sale->update(['upi_intent_id' => $locked->id]);

                $locked->update([
                    'status' => PosPaymentIntentStatus::Completed,
                    'utr' => $utr,
                    'verified_by' => $actor->id,
                    'verified_at' => now(),
                    'bank_checked_at' => now(),
                    'sale_id' => $sale->id,
                ]);

                Log::info('POS UPI intent verified', [
                    'intent_id' => $locked->id,
                    'public_ref' => $locked->public_ref,
                    'sale_id' => $sale->id,
                    'verified_by' => $actor->id,
                ]);

                return $sale->fresh(['lines.product', 'serials.serial', 'customer', 'branch', 'upiIntent']) ?? $sale;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $completed = PosPaymentIntent::query()->find($intent->id);
            if ($completed?->status === PosPaymentIntentStatus::Completed && $completed->sale_id !== null) {
                $sale = InventorySale::query()->findOrFail($completed->sale_id);

                return $sale->fresh(['lines.product', 'serials.serial', 'customer', 'branch', 'upiIntent']) ?? $sale;
            }

            $other = PosPaymentIntent::query()->where('utr', $utr)->where('id', '!=', $intent->id)->first();
            if ($other !== null) {
                throw ValidationException::withMessages([
                    'utr' => 'That UTR is already recorded on another verified UPI payment.',
                ]);
            }

            throw $exception;
        }
    }
}
