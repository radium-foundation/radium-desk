<?php

namespace App\Services\HardwareFulfilment\Data;

final class HardwareBulkDocumentOutcome
{
    /**
     * @param  list<string>  $succeededSourceIds
     * @param  list<array{source_id: string, reason: string}>  $excluded
     * @param  list<array{source_id: string, reason: string}>  $failed
     */
    public function __construct(
        public readonly ?string $downloadUrl,
        public readonly array $succeededSourceIds,
        public readonly array $excluded,
        public readonly array $failed,
    ) {}

    public function isPartial(): bool
    {
        return $this->excluded !== [] || $this->failed !== [];
    }

    public function succeededCount(): int
    {
        return count($this->succeededSourceIds);
    }
}
