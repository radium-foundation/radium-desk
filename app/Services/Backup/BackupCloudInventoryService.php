<?php

namespace App\Services\Backup;

use Illuminate\Support\Carbon;

/**
 * Read-only Cloud backup inventory from a sanitized local JSON index.
 *
 * Never executes backup/prune commands or exposes secrets, credentials,
 * remote paths, artifact filenames, or encryption details.
 */
class BackupCloudInventoryService
{
    private const BACKUP_ID_PATTERN = '/^[0-9]{8}T[0-9]{6}Z$/';

    /**
     * @var list<string>
     */
    private const FORBIDDEN_ENTRY_KEYS = [
        'remote_path',
        'remote_host',
        'filename',
        'sha256',
        'encryption',
        'artifacts',
        'upload',
        'passphrase',
        'ssh',
    ];

    public function __construct(
        private readonly BackupStatusService $backupStatusService,
    ) {}

    /**
     * @return array{
     *     index_accessible: bool,
     *     index_parse_error: bool,
     *     index_unavailable_message: ?string,
     *     index_parse_error_message: ?string,
     *     read_only_notice: string,
     *     entries: list<array<string, mixed>>
     * }
     */
    public function summary(): array
    {
        $path = $this->indexPath();

        if ($path === null || ! is_file($path)) {
            return $this->buildSummary(
                indexAccessible: false,
                indexParseError: false,
                entries: [],
                unavailableMessage: 'Cloud backup inventory is not available yet. The sanitized index has not been generated or is not readable by the web process.',
            );
        }

        if (! is_readable($path)) {
            return $this->buildSummary(
                indexAccessible: false,
                indexParseError: false,
                entries: [],
                unavailableMessage: 'Cloud backup inventory is not available yet. The sanitized index has not been generated or is not readable by the web process.',
            );
        }

        $parsed = $this->parseIndexFile($path);

        if ($parsed['parse_error']) {
            return $this->buildSummary(
                indexAccessible: true,
                indexParseError: true,
                entries: [],
                parseErrorMessage: 'Cloud backup inventory index could not be read or parsed. Contact an operator to regenerate the sanitized index.',
            );
        }

        return $this->buildSummary(
            indexAccessible: true,
            indexParseError: false,
            entries: $parsed['entries'],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return array{
     *     index_accessible: bool,
     *     index_parse_error: bool,
     *     index_unavailable_message: ?string,
     *     index_parse_error_message: ?string,
     *     read_only_notice: string,
     *     entries: list<array<string, mixed>>
     * }
     */
    private function buildSummary(
        bool $indexAccessible,
        bool $indexParseError,
        array $entries,
        ?string $unavailableMessage = null,
        ?string $parseErrorMessage = null,
    ): array {
        return [
            'index_accessible' => $indexAccessible,
            'index_parse_error' => $indexParseError,
            'index_unavailable_message' => $unavailableMessage,
            'index_parse_error_message' => $parseErrorMessage,
            'read_only_notice' => 'Read-only Cloud inventory. This list is built from a sanitized local index and does not expose backup contents or credentials.',
            'entries' => $entries,
        ];
    }

    private function indexPath(): ?string
    {
        $path = trim((string) config('backup.cloud_inventory_path', ''));

        return $path === '' ? null : $path;
    }

    /**
     * @return array{entries: list<array<string, mixed>>, parse_error: bool}
     */
    private function parseIndexFile(string $path): array
    {
        $raw = @file_get_contents($path);

        if ($raw === false || trim($raw) === '') {
            return [
                'entries' => [],
                'parse_error' => true,
            ];
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [
                'entries' => [],
                'parse_error' => true,
            ];
        }

        if (! is_array($data)) {
            return [
                'entries' => [],
                'parse_error' => true,
            ];
        }

        $entries = $data['entries'] ?? null;

        if (! is_array($entries)) {
            return [
                'entries' => [],
                'parse_error' => true,
            ];
        }

        $parsed = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $normalized = $this->normalizeEntry($entry);

            if ($normalized !== null) {
                $parsed[] = $normalized;
            }
        }

        usort($parsed, static fn (array $a, array $b): int => strcmp((string) $b['backup_id'], (string) $a['backup_id']));

        $limit = max((int) config('backup.cloud_inventory_limit', 10), 1);

        return [
            'entries' => array_slice($parsed, 0, $limit),
            'parse_error' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>|null
     */
    private function normalizeEntry(array $entry): ?array
    {
        foreach (self::FORBIDDEN_ENTRY_KEYS as $forbiddenKey) {
            if (array_key_exists($forbiddenKey, $entry)) {
                return null;
            }
        }

        $backupId = trim((string) ($entry['backup_id'] ?? ''));

        if (! preg_match(self::BACKUP_ID_PATTERN, $backupId)) {
            return null;
        }

        $timestampUtc = trim((string) ($entry['timestamp_utc'] ?? ''));

        if ($timestampUtc === '' || ! $this->isIso8601Utc($timestampUtc)) {
            $timestampUtc = $this->timestampFromBackupId($backupId);

            if ($timestampUtc === null) {
                return null;
            }
        }

        $size = $entry['total_size_bytes'] ?? null;

        if (! is_numeric($size) || (int) $size < 0) {
            return null;
        }

        if (! $this->isBooleanField($entry, 'manifest_present') || ! $this->isBooleanField($entry, 'upload_complete')) {
            return null;
        }

        $manifestPresent = (bool) $entry['manifest_present'];
        $uploadComplete = (bool) $entry['upload_complete'];
        $sizeBytes = (int) $size;

        return [
            'backup_id' => $backupId,
            'timestamp_utc' => $timestampUtc,
            'timestamp_label' => $this->formatTimestampIst($timestampUtc),
            'total_size_bytes' => $sizeBytes,
            'total_size_label' => $this->backupStatusService->formatBytes($sizeBytes),
            'manifest_present' => $manifestPresent,
            'manifest_present_label' => $manifestPresent ? 'Yes' : 'No',
            'upload_complete' => $uploadComplete,
            'upload_complete_label' => $uploadComplete ? 'Yes' : 'No',
        ];
    }

    private function isBooleanField(array $entry, string $key): bool
    {
        if (! array_key_exists($key, $entry)) {
            return false;
        }

        return is_bool($entry[$key]);
    }

    private function isIso8601Utc(string $value): bool
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value)) {
            return false;
        }

        try {
            Carbon::createFromFormat('Y-m-d\TH:i:s\Z', $value, 'UTC');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function timestampFromBackupId(string $backupId): ?string
    {
        if (! preg_match(self::BACKUP_ID_PATTERN, $backupId)) {
            return null;
        }

        return sprintf(
            '%s-%s-%sT%s:%s:%sZ',
            substr($backupId, 0, 4),
            substr($backupId, 4, 2),
            substr($backupId, 6, 2),
            substr($backupId, 9, 2),
            substr($backupId, 11, 2),
            substr($backupId, 13, 2),
        );
    }

    private function formatTimestampIst(string $timestampUtc): string
    {
        try {
            return Carbon::createFromFormat('Y-m-d\TH:i:s\Z', $timestampUtc, 'UTC')
                ->timezone('Asia/Kolkata')
                ->format('Y-m-d H:i:s T');
        } catch (\Throwable) {
            return $timestampUtc;
        }
    }
}
