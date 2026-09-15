<?php

namespace App\Services\StatutoryInvoice\Data;

final class EInvoiceInputReadinessResult
{
    /**
     * @param  list<string>  $blockedReasons
     */
    public function __construct(
        public readonly bool $ready,
        public readonly array $blockedReasons,
    ) {}

    public function statusLabel(): string
    {
        return $this->ready ? 'READY' : 'BLOCKED';
    }

    /**
     * @return list<string>
     */
    public function blockedReasonLines(): array
    {
        if ($this->ready) {
            return [];
        }

        return array_values(array_unique($this->blockedReasons));
    }
}
