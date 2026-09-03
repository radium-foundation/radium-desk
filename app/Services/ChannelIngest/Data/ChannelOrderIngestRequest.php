<?php

namespace App\Services\ChannelIngest\Data;

use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Services\StatutoryInvoice\Data\StatutoryInvoiceMintRequest;

final class ChannelOrderIngestRequest
{
    /**
     * @param  list<ChannelOrderLineDraft>  $lines
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly StatutoryInvoiceChannel $channel,
        public readonly StatutoryInvoiceSourceType $sourceType,
        public readonly string $sourceId,
        public readonly array $lines,
        public readonly string $paymentStatus,
        public readonly string $currency,
        public readonly ?string $sourceOrderId = null,
        public readonly ?string $paymentProvider = null,
        public readonly ?string $paymentReference = null,
        public readonly ?string $paymentMethod = null,
        public readonly ?string $customerName = null,
        public readonly ?string $customerPhone = null,
        public readonly ?string $customerEmail = null,
        public readonly ?string $buyerGstin = null,
        public readonly ?string $billingAddress = null,
        public readonly ?string $shippingAddress = null,
        public readonly ?string $sellerGstin = null,
        public readonly ?string $sellerName = null,
        public readonly ?string $branchCode = null,
        public readonly ?string $placeOfSupplyState = null,
        public readonly ?float $discount = null,
        public readonly array $metadata = [],
        public readonly ?string $orderedAt = null,
        public readonly ?string $paidAt = null,
        public readonly ?int $supportOrderId = null,
    ) {}

    public function idempotencyKey(): string
    {
        return StatutoryInvoiceMintRequest::sourceKey($this->channel, $this->sourceType, $this->sourceId);
    }
}
