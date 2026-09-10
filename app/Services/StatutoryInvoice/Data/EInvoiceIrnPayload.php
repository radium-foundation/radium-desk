<?php

namespace App\Services\StatutoryInvoice\Data;

final class EInvoiceIrnPayload
{
    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $seller
     * @param  array<string, mixed>  $buyer
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $values
     * @param  list<string>  $gaps
     */
    public function __construct(
        public readonly string $supplyType,
        public readonly array $document,
        public readonly array $seller,
        public readonly array $buyer,
        public readonly array $items,
        public readonly array $values,
        public readonly array $gaps,
    ) {}

    public function isSubmittable(): bool
    {
        return $this->gaps === [];
    }

    /**
     * Test-only IRP field fills. Production mapping never calls this.
     *
     * @param  array<string, mixed>  $seller
     * @param  array<string, mixed>  $buyer
     * @param  list<array<string, mixed>>  $items
     * @param  list<string>  $removeGaps
     */
    public function withTestIrpFixtures(
        array $seller,
        array $buyer,
        array $items,
        array $removeGaps,
    ): self {
        return new self(
            supplyType: $this->supplyType,
            document: $this->document,
            seller: array_merge($this->seller, $seller),
            buyer: array_merge($this->buyer, $buyer),
            items: $items,
            values: $this->values,
            gaps: array_values(array_diff($this->gaps, $removeGaps)),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'supply_type' => $this->supplyType,
            'document' => $this->document,
            'seller' => $this->seller,
            'buyer' => $this->buyer,
            'items' => $this->items,
            'values' => $this->values,
            'gaps' => $this->gaps,
        ];
    }
}
