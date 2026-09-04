<?php

namespace App\Services\RadiumBoxRead\Data;

readonly class RadiumBoxReadLookupResult
{
    /**
     * @param  list<RadiumBoxReadOrderRecord>  $records
     */
    public function __construct(
        public array $records,
        public int $total,
        public int $page,
        public int $perPage,
        public string $identifierType,
        public string $identifier,
        public string $identifierColumn,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'data' => array_map(fn (RadiumBoxReadOrderRecord $record): array => $record->toArray(), $this->records),
            'meta' => [
                'total' => $this->total,
                'page' => $this->page,
                'per_page' => $this->perPage,
                'identifier_type' => $this->identifierType,
                'identifier' => $this->identifier,
                'identifier_column' => $this->identifierColumn,
            ],
        ];
    }
}
