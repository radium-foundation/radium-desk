<?php

namespace App\Services\Pos;

use App\Enums\InventoryReservationStatus;
use App\Enums\PosPaymentIntentStatus;
use App\Models\FinanceBankAccount;
use App\Models\InventoryBranch;
use App\Models\InventoryReservation;
use App\Models\PosPaymentIntent;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\PosSaleService;
use App\Support\Pos\PosUpiUriBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PosUpiIntentService
{
    public function __construct(
        private readonly InventoryStockService $stock,
        private readonly PosSaleService $sales,
        private readonly PosUpiUriBuilder $uris,
    ) {}

    /**
     * Active receiving accounts that have an enabled UPI profile.
     *
     * @return Collection<int, FinanceBankAccount>
     */
    public function enabledReceivingAccounts(): Collection
    {
        return FinanceBankAccount::query()
            ->where('is_active', true)
            ->whereHas('upiProfile', fn ($query) => $query->where('is_enabled', true))
            ->with('upiProfile')
            ->ordered()
            ->get();
    }

    /**
     * @param  array{name: string, phone: string, email?: string|null, gstin?: string|null}  $customer
     * @param  list<array{
     *     product_id: int,
     *     variant_id?: int|null,
     *     qty: int,
     *     serials?: list<string>|string|null,
     *     unit_price?: float|string|null,
     *     discount?: float|string|null
     * }>  $lines
     */
    public function create(
        InventoryBranch $branch,
        array $customer,
        array $lines,
        int $receivingBankAccountId,
        User $actor,
        float $headerDiscount = 0,
        ?string $notes = null,
        ?string $saleIdempotencyKey = null,
        array $statutory = [],
    ): PosPaymentIntent {
        if (! $branch->is_active) {
            throw ValidationException::withMessages([
                'branch_id' => 'Branch is inactive.',
            ]);
        }

        $name = trim((string) ($customer['name'] ?? ''));
        $phone = preg_replace('/\s+/', '', (string) ($customer['phone'] ?? '')) ?? '';
        if ($name === '' || $phone === '') {
            throw ValidationException::withMessages([
                'customer_phone' => 'Customer name and phone are required.',
            ]);
        }

        $saleIdempotencyKey = $saleIdempotencyKey !== null && trim($saleIdempotencyKey) !== ''
            ? trim($saleIdempotencyKey)
            : 'upi-intent:'.(string) Str::uuid();

        $existing = PosPaymentIntent::query()
            ->where('sale_idempotency_key', $saleIdempotencyKey)
            ->first();
        if ($existing !== null) {
            return $this->refreshExpiry($existing, $actor);
        }

        $quote = $this->sales->quoteTotals($lines, $headerDiscount);
        $amount = PosUpiUriBuilder::formatAmount($quote['total']);

        $account = FinanceBankAccount::query()
            ->with('upiProfile')
            ->find($receivingBankAccountId);
        if ($account === null || ! $account->is_active) {
            throw ValidationException::withMessages([
                'receiving_bank_account_id' => 'Select an active receiving bank account.',
            ]);
        }

        $profile = $account->upiProfile;
        if ($profile === null || ! $profile->is_enabled) {
            throw ValidationException::withMessages([
                'receiving_bank_account_id' => 'That account is not enabled for UPI collection.',
            ]);
        }

        $minutes = (int) config('pos.upi_intent_expires_minutes', 60);

        return DB::transaction(function () use (
            $branch,
            $customer,
            $lines,
            $actor,
            $headerDiscount,
            $notes,
            $saleIdempotencyKey,
            $amount,
            $account,
            $profile,
            $name,
            $phone,
            $minutes,
            $statutory,
        ): PosPaymentIntent {
            $reservation = $this->stock->reserveForCart(
                $branch,
                $lines,
                $actor,
                'UPI pending payment',
            );

            $tr = 'RD'.strtoupper(bin2hex(random_bytes(8)));
            $uri = $this->uris->build($profile->vpa, $profile->payee_name, $amount, $tr);

            $intent = PosPaymentIntent::query()->create([
                'public_ref' => 'UPI-TMP-'.strtoupper(bin2hex(random_bytes(6))),
                'tr' => $tr,
                'sale_idempotency_key' => $saleIdempotencyKey,
                'status' => PosPaymentIntentStatus::Pending,
                'branch_id' => $branch->id,
                'receiving_bank_account_id' => $account->id,
                'upi_profile_id' => $profile->id,
                'vpa_snapshot' => $profile->vpa,
                'payee_name_snapshot' => $profile->payee_name,
                'amount' => $amount,
                'currency' => 'INR',
                'upi_uri' => $uri,
                'cart_payload' => [
                    'branch_id' => $branch->id,
                    'customer' => [
                        'name' => $name,
                        'phone' => $phone,
                        'email' => $customer['email'] ?? null,
                    ],
                    'lines' => $lines,
                    'discount' => $headerDiscount,
                    'notes' => $notes,
                    'payment_method' => 'UPI',
                    'statutory' => [
                        'buyer_gstin' => $statutory['buyer_gstin'] ?? ($customer['gstin'] ?? null),
                        'billing_address' => $statutory['billing_address'] ?? null,
                        'place_of_supply_state' => $statutory['place_of_supply_state'] ?? null,
                    ],
                ],
                'customer_name' => $name,
                'customer_phone' => $phone,
                'reservation_id' => $reservation->id,
                'created_by' => $actor->id,
                'expires_at' => $minutes > 0 ? now()->addMinutes($minutes) : null,
            ]);

            $intent->update([
                'public_ref' => sprintf('UPI-%s-%06d', now()->format('Ymd'), $intent->id),
            ]);

            Log::info('POS UPI intent created', [
                'intent_id' => $intent->id,
                'public_ref' => $intent->public_ref,
                'branch_id' => $branch->id,
                'receiving_bank_account_id' => $account->id,
            ]);

            return $intent->fresh(['receivingBankAccount', 'branch']) ?? $intent;
        });
    }

    public function refreshExpiry(PosPaymentIntent $intent, User $actor): PosPaymentIntent
    {
        if ($intent->status !== PosPaymentIntentStatus::Pending) {
            return $intent;
        }

        if ($intent->expires_at !== null && $intent->expires_at->isPast()) {
            return $this->closeUnpaid($intent, $actor, PosPaymentIntentStatus::Abandoned, 'Expired');
        }

        return $intent;
    }

    public function abandon(PosPaymentIntent $intent, User $actor, ?string $reason = null): PosPaymentIntent
    {
        return $this->closeUnpaid(
            $intent,
            $actor,
            PosPaymentIntentStatus::Abandoned,
            $reason !== null && trim($reason) !== '' ? trim($reason) : 'Abandoned',
        );
    }

    public function cancelUnpaid(PosPaymentIntent $intent, User $actor, ?string $reason = null): PosPaymentIntent
    {
        return $this->closeUnpaid(
            $intent,
            $actor,
            PosPaymentIntentStatus::Cancelled,
            $reason !== null && trim($reason) !== '' ? trim($reason) : 'Cancelled',
        );
    }

    private function closeUnpaid(
        PosPaymentIntent $intent,
        User $actor,
        PosPaymentIntentStatus $toStatus,
        string $reason,
    ): PosPaymentIntent {
        return DB::transaction(function () use ($intent, $actor, $toStatus, $reason): PosPaymentIntent {
            $locked = PosPaymentIntent::query()->lockForUpdate()->findOrFail($intent->id);

            if ($locked->status === $toStatus) {
                return $locked;
            }

            if ($locked->status !== PosPaymentIntentStatus::Pending) {
                throw ValidationException::withMessages([
                    'intent' => 'Only a pending UPI payment can be closed without completing the sale.',
                ]);
            }

            if ($locked->reservation_id !== null) {
                $reservation = InventoryReservation::query()->lockForUpdate()->find($locked->reservation_id);
                if ($reservation !== null && $reservation->status === InventoryReservationStatus::Active) {
                    $this->stock->releaseReservation($reservation, $actor, $reason);
                }
            }

            $attributes = [
                'status' => $toStatus,
            ];
            if ($toStatus === PosPaymentIntentStatus::Abandoned) {
                $attributes['abandoned_at'] = now();
                $attributes['abandon_reason'] = $reason;
            } else {
                $attributes['cancelled_at'] = now();
                $attributes['cancel_reason'] = $reason;
            }

            $locked->update($attributes);

            Log::info('POS UPI intent closed unpaid', [
                'intent_id' => $locked->id,
                'public_ref' => $locked->public_ref,
                'status' => $toStatus->value,
            ]);

            return $locked->fresh(['receivingBankAccount', 'branch']) ?? $locked;
        });
    }
}
