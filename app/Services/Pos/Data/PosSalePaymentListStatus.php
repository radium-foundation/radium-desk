<?php

namespace App\Services\Pos\Data;

final class PosSalePaymentListStatus
{
    public function __construct(
        public readonly string $label,
        public readonly string $badgeClass,
        public readonly ?string $accessibleTitle = null,
    ) {}

    public static function unavailable(string $reason): self
    {
        return new self(
            label: '—',
            badgeClass: 'text-muted',
            accessibleTitle: $reason,
        );
    }
}
