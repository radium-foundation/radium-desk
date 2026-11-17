<?php

namespace App\Services\HardwareFulfilment\Data;

final class HardwareBulkDownloadOutcome
{
    /**
     * @param  list<HardwareBulkDownloadRow>  $rows
     */
    public function __construct(
        public readonly array $rows,
        public readonly ?string $archivePath,
        public readonly string $archiveFilename,
    ) {}

    public function selectedCount(): int
    {
        return count($this->rows);
    }

    public function includedCount(): int
    {
        return count(array_filter(
            $this->rows,
            static fn (HardwareBulkDownloadRow $row): bool => $row->result === 'included',
        ));
    }

    public function excludedCount(): int
    {
        return count(array_filter(
            $this->rows,
            static fn (HardwareBulkDownloadRow $row): bool => $row->result === 'excluded',
        ));
    }

    public function failedCount(): int
    {
        return count(array_filter(
            $this->rows,
            static fn (HardwareBulkDownloadRow $row): bool => $row->result === 'failed',
        ));
    }

    public function hasArchive(): bool
    {
        return $this->archivePath !== null && is_file($this->archivePath);
    }
}
