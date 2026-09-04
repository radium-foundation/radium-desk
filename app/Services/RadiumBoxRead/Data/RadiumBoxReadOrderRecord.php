<?php

namespace App\Services\RadiumBoxRead\Data;

readonly class RadiumBoxReadOrderRecord
{
    /**
     * @param  list<RadiumBoxReadInvoice>  $invoices
     * @param  list<RadiumBoxReadLine>  $lines
     * @param  list<RadiumBoxReadHistoryEvent>  $history
     */
    public function __construct(
        public ?RadiumBoxReadCommercialOrder $commercial,
        public ?RadiumBoxReadRdOrder $rdOrder,
        public ?RadiumBoxReadCustomer $customer,
        public array $invoices,
        public array $lines,
        public array $history,
        public string $matchedIdentifierType,
        public string $matchedIdentifierColumn,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'commercial' => $this->commercial?->toArray(),
            'rd_order' => $this->rdOrder?->toArray(),
            'customer' => $this->customer?->toArray(),
            'invoices' => array_map(fn (RadiumBoxReadInvoice $invoice): array => $invoice->toArray(), $this->invoices),
            'lines' => array_map(fn (RadiumBoxReadLine $line): array => $line->toArray(), $this->lines),
            'history' => array_map(fn (RadiumBoxReadHistoryEvent $event): array => $event->toArray(), $this->history),
            'matched_identifier_type' => $this->matchedIdentifierType,
            'matched_identifier_column' => $this->matchedIdentifierColumn,
        ];
    }
}
