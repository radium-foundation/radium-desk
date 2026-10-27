<?php

namespace App\Services\StatutoryInvoice\Data;

use App\Enums\EInvoiceCancelOutcome;

final class IrnCancellationReconciliationResult
{
    public const STATE_CANCELLED = 'cancelled';
    public const STATE_ACTIVE = 'active';
    public const STATE_UNKNOWN = 'unknown';

    public function __construct(
        public readonly string $state,
        public readonly ?EInvoiceCancelResult $cancelResult = null,
        public readonly ?string $message = null,
    ) {}

    public static function cancelled(EInvoiceCancelResult $result, ?string $message = null): self
    {
        return new self(self::STATE_CANCELLED, $result, $message);
    }

    public static function active(?string $message = null): self
    {
        return new self(self::STATE_ACTIVE, null, $message);
    }

    public static function unknown(?string $message = null): self
    {
        return new self(self::STATE_UNKNOWN, null, $message);
    }

    public function isCancelled(): bool
    {
        return $this->state === self::STATE_CANCELLED;
    }

    public function isActive(): bool
    {
        return $this->state === self::STATE_ACTIVE;
    }
}
