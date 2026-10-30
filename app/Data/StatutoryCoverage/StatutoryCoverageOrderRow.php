<?php

namespace App\Data\StatutoryCoverage;

use App\Enums\StatutoryCoverage\StatutoryCoverageClassification;

final readonly class StatutoryCoverageOrderRow
{
    public function __construct(
        public string $sourceId,
        public StatutoryCoverageClassification $classification,
        public ?int $commerceOrderId,
        public ?int $statutoryInvoiceId,
        public string $reason,
        public bool $fromDeskPopulation,
        public bool $fromSourcePopulation,
    ) {}

    public function toArray(): array
    {
        return [
            'source_id' => $this->sourceId,
            'classification' => $this->classification->value,
            'commerce_order_id' => $this->commerceOrderId,
            'statutory_invoice_id' => $this->statutoryInvoiceId,
            'reason' => $this->reason,
            'from_desk_population' => $this->fromDeskPopulation,
            'from_source_population' => $this->fromSourcePopulation,
        ];
    }
}
