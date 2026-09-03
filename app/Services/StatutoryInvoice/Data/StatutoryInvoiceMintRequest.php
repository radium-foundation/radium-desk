<?php

namespace App\Services\StatutoryInvoice\Data;

use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;

final class StatutoryInvoiceMintRequest
{
    /**
     * @param  list<StatutoryInvoiceLineDraft>  $lines
     */
    public function __construct(
        public readonly StatutoryInvoiceChannel $channel,
        public readonly StatutoryInvoiceSourceType $sourceType,
        public readonly string $sourceId,
        public readonly array $lines,
        public readonly ?string $sourceOrderId = null,
        public readonly ?int $inventorySaleId = null,
        public readonly ?int $supportOrderId = null,
        public readonly ?int $branchId = null,
        public readonly ?string $sellerGstin = null,
        public readonly ?string $sellerName = null,
        public readonly ?string $buyerName = null,
        public readonly ?string $buyerPhone = null,
        public readonly ?string $buyerGstin = null,
        public readonly ?string $billingAddress = null,
        public readonly ?string $placeOfSupplyState = null,
        public readonly float $discount = 0,
        public readonly ?string $paymentMethod = null,
        public readonly ?string $paymentReference = null,
        public readonly ?string $internalReceiptNumber = null,
    ) {}

    public function idempotencyKey(): string
    {
        return self::sourceKey($this->channel, $this->sourceType, $this->sourceId);
    }

    public static function sourceKey(
        StatutoryInvoiceChannel $channel,
        StatutoryInvoiceSourceType $sourceType,
        string $sourceId,
    ): string {
        return sprintf(
            'statutory:%s:%s:%s',
            $channel->value,
            $sourceType->value,
            $sourceId,
        );
    }
}
