<?php

namespace App\Enums;

enum RefundRevokeCustomerOutcome: string
{
    case WantsService = 'wants_service';
    case WantsOriginalPaymentMethod = 'wants_original_payment_method';

    public function label(): string
    {
        return match ($this) {
            self::WantsService => 'Wants the service',
            self::WantsOriginalPaymentMethod => 'Wants money back to original payment method',
        };
    }

    public function isImplemented(): bool
    {
        return $this === self::WantsService;
    }
}
