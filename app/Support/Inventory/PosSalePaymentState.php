<?php

namespace App\Support\Inventory;

use App\Models\InventorySale;
use App\Models\PaymentAllocation;
use Illuminate\Validation\ValidationException;

final class PosSalePaymentState
{
    public const PAYMENT_PENDING_REFERENCE = '__PAYMENT_PENDING__';

    public const PAYMENT_STATUS_PAID = 'paid';

    public const PAYMENT_STATUS_PENDING = 'pending';

    public static function isPaymentPending(?InventorySale $sale): bool
    {
        if ($sale === null) {
            return false;
        }

        return trim((string) $sale->payment_reference) === self::PAYMENT_PENDING_REFERENCE;
    }

    public static function displaysAsPaymentPending(?InventorySale $sale): bool
    {
        if (! self::isPaymentPending($sale)) {
            return false;
        }

        $invoice = $sale->statutoryInvoice;
        if ($invoice === null) {
            return true;
        }

        $received = (float) PaymentAllocation::query()
            ->where('statutory_invoice_id', $invoice->id)
            ->sum('amount');

        return $received <= 0.001;
    }

    public static function assertUsablePaymentReference(?string $reference): void
    {
        if (self::nullableTrim($reference) === self::PAYMENT_PENDING_REFERENCE) {
            throw ValidationException::withMessages([
                'payment_reference' => 'That reference is reserved for unpaid sales. Leave it blank or use a real payment reference.',
            ]);
        }
    }

    /**
     * @return array{payment_method: ?string, payment_reference: string}
     */
    public static function pendingPaymentAttributes(?string $expectedMethod): array
    {
        $expectedMethod = self::nullableTrim($expectedMethod);

        return [
            'payment_method' => $expectedMethod,
            'payment_reference' => self::PAYMENT_PENDING_REFERENCE,
        ];
    }

    public static function nullableTrim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
