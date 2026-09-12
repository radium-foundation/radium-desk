<?php

namespace App\Data;

readonly class HistoricalSearchHit
{
    public function __construct(
        public string $documentType,
        public int $entityId,
        public string $title,
        public string $subtitle,
        public ?string $occurredOn,
        public string $sourceLineage,
        public ?string $sourceDatabase = null,
        public ?string $sourceTable = null,
        public ?string $sourcePk = null,
        public bool $partialIngest = false,
    ) {}

    /**
     * @return array{document_type: string, entity_id: int}
     */
    public function dedupeKey(): array
    {
        return [
            'document_type' => $this->documentType,
            'entity_id' => $this->entityId,
        ];
    }
}
