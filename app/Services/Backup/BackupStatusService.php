<?php

namespace App\Services\Backup;

use Illuminate\Support\Carbon;

/**
 * Read-only backup metadata from local staging manifests.
 *
 * Never executes backup/prune commands or exposes secrets, credentials, or artifacts.
 */
class BackupStatusService
{
    private const BACKUP_ID_PATTERN = '/^[0-9]{8}T[0-9]{6}Z$/';

    /**
     * @return array{
     *     staging_accessible: bool,
     *     staging_unavailable_message: ?string,
     *     schedule_label: string,
     *     read_only_notice: string,
     *     latest: ?array<string, mixed>,
     *     history: list<array<string, mixed>>
     * }
     */
    public function summary(): array
    {
        $runsRoot = $this->runsRoot();
        $stagingAccessible = $runsRoot !== null && is_dir($runsRoot) && is_readable($runsRoot);

        $entries = $stagingAccessible ? $this->collectRunEntries($runsRoot) : [];

        $successful = array_values(array_filter(
            $entries,
            static fn (array $entry): bool => ($entry['integrity_status'] ?? '') === 'ok'
                && in_array($entry['phase'] ?? '', ['local_staging', 'cloud_uploaded'], true),
        ));

        usort($successful, static fn (array $a, array $b): int => strcmp($b['backup_id'], $a['backup_id']));

        $latest = $successful[0] ?? null;

        $historyLimit = (int) config('backup.history_limit', 10);
        $history = array_slice($entries, 0, $historyLimit);

        return [
            'staging_accessible' => $stagingAccessible,
            'staging_unavailable_message' => $stagingAccessible
                ? null
                : 'Backup metadata is not readable by the web process. Scheduled backups may still be running on the server.',
            'schedule_label' => (string) config('backup.schedule_label', '02:00 IST and 14:00 IST'),
            'read_only_notice' => 'Phase 1 — read-only status. Manual backup and restore are not available from Desk yet.',
            'latest' => $latest,
            'history' => $history,
        ];
    }

    private function runsRoot(): ?string
    {
        $root = trim((string) config('backup.staging_root', ''));

        if ($root === '') {
            return null;
        }

        return rtrim($root, '/').'/runs';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectRunEntries(string $runsRoot): array
    {
        $handles = @scandir($runsRoot, SCANDIR_SORT_NONE);

        if ($handles === false) {
            return [];
        }

        $backupIds = [];

        foreach ($handles as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            if (! preg_match(self::BACKUP_ID_PATTERN, $name)) {
                continue;
            }

            $backupIds[] = $name;
        }

        rsort($backupIds, SORT_STRING);

        $limit = max((int) config('backup.history_limit', 10), 1);
        $backupIds = array_slice($backupIds, 0, $limit * 2);

        $entries = [];

        foreach ($backupIds as $backupId) {
            $entries[] = $this->parseRunDirectory($runsRoot.'/'.$backupId, $backupId);
        }

        usort($entries, static fn (array $a, array $b): int => strcmp($b['backup_id'], $a['backup_id']));

        return array_slice($entries, 0, $limit);
    }

    /**
     * @return array<string, mixed>
     */
    private function parseRunDirectory(string $runDir, string $backupId): array
    {
        $manifestPath = $runDir.'/manifest.json';

        if (! is_file($manifestPath) || ! is_readable($manifestPath)) {
            return $this->incompleteEntry($backupId, 'missing_manifest');
        }

        $raw = @file_get_contents($manifestPath);

        if ($raw === false || trim($raw) === '') {
            return $this->malformedEntry($backupId, 'empty_manifest');
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->malformedEntry($backupId, 'invalid_json');
        }

        if (! is_array($data)) {
            return $this->malformedEntry($backupId, 'invalid_json');
        }

        $manifestBackupId = (string) ($data['backup_id'] ?? '');

        if ($manifestBackupId !== $backupId) {
            return $this->malformedEntry($backupId, 'backup_id_mismatch');
        }

        $application = is_array($data['application'] ?? null) ? $data['application'] : [];
        $artifacts = is_array($data['artifacts'] ?? null) ? $data['artifacts'] : [];
        $database = $this->artifactByRole($artifacts, 'database');
        $secrets = $this->artifactByRole($artifacts, 'secrets');

        $phase = (string) ($data['phase'] ?? 'unknown');
        $integrity = $this->resolveIntegrityStatus($database, $secrets);
        $cloudStatus = $this->resolveCloudUploadStatus($phase, $data['upload'] ?? null);

        return [
            'backup_id' => $backupId,
            'created_at' => $this->formatTimestamp((string) ($data['created_at'] ?? '')),
            'phase' => $phase,
            'status_label' => $this->statusLabel($integrity, $phase),
            'application_version' => $this->nullableString($application['version'] ?? null),
            'application_build' => $this->nullableString($application['build'] ?? null),
            'database_size_bytes' => $database['size_bytes'] ?? null,
            'database_size_label' => $this->formatBytes($database['size_bytes'] ?? null),
            'secrets_size_bytes' => $secrets['size_bytes'] ?? null,
            'secrets_size_label' => $this->formatBytes($secrets['size_bytes'] ?? null),
            'cloud_upload_status' => $cloudStatus,
            'cloud_upload_status_label' => $this->cloudUploadLabel($cloudStatus),
            'integrity_status' => $integrity,
            'integrity_status_label' => $this->integrityLabel($integrity),
        ];
    }

    /**
     * @param  list<mixed>  $artifacts
     * @return array{size_bytes: ?int, sha256: ?string}
     */
    private function artifactByRole(array $artifacts, string $role): array
    {
        foreach ($artifacts as $artifact) {
            if (! is_array($artifact)) {
                continue;
            }

            if ((string) ($artifact['role'] ?? '') !== $role) {
                continue;
            }

            $size = $artifact['size_bytes'] ?? null;

            return [
                'size_bytes' => is_numeric($size) ? (int) $size : null,
                'sha256' => $this->nullableString($artifact['sha256'] ?? null),
            ];
        }

        return [
            'size_bytes' => null,
            'sha256' => null,
        ];
    }

    /**
     * @param  array{size_bytes: ?int, sha256: ?string}  $database
     * @param  array{size_bytes: ?int, sha256: ?string}  $secrets
     */
    private function resolveIntegrityStatus(array $database, array $secrets): string
    {
        if ($database['size_bytes'] === null || $secrets['size_bytes'] === null) {
            return 'incomplete';
        }

        if (! $this->isSha256($database['sha256']) || ! $this->isSha256($secrets['sha256'])) {
            return 'incomplete';
        }

        return 'ok';
    }

    private function resolveCloudUploadStatus(string $phase, mixed $upload): string
    {
        if ($phase === 'cloud_uploaded' && is_array($upload) && ($upload['status'] ?? '') === 'completed') {
            return 'completed';
        }

        if ($phase === 'local_staging') {
            return 'not_uploaded';
        }

        if ($phase === 'cloud_uploaded') {
            return 'unknown';
        }

        return 'unknown';
    }

    /**
     * @return array<string, mixed>
     */
    private function incompleteEntry(string $backupId, string $reason): array
    {
        return [
            'backup_id' => $backupId,
            'created_at' => null,
            'phase' => 'unknown',
            'status_label' => 'Incomplete',
            'application_version' => null,
            'application_build' => null,
            'database_size_bytes' => null,
            'database_size_label' => '—',
            'secrets_size_bytes' => null,
            'secrets_size_label' => '—',
            'cloud_upload_status' => 'unknown',
            'cloud_upload_status_label' => 'Unknown',
            'integrity_status' => 'incomplete',
            'integrity_status_label' => $this->integrityLabel('incomplete'),
            'incomplete_reason' => $reason,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function malformedEntry(string $backupId, string $reason): array
    {
        $entry = $this->incompleteEntry($backupId, $reason);
        $entry['integrity_status'] = 'malformed';
        $entry['integrity_status_label'] = $this->integrityLabel('malformed');
        $entry['status_label'] = 'Malformed';

        return $entry;
    }

    private function statusLabel(string $integrity, string $phase): string
    {
        if ($integrity === 'malformed') {
            return 'Malformed';
        }

        if ($integrity !== 'ok') {
            return 'Incomplete';
        }

        return $phase === 'cloud_uploaded' ? 'Complete (cloud)' : 'Complete (local)';
    }

    private function integrityLabel(string $integrity): string
    {
        return match ($integrity) {
            'ok' => 'Manifest OK',
            'malformed' => 'Malformed manifest',
            default => 'Incomplete manifest',
        };
    }

    private function cloudUploadLabel(string $status): string
    {
        return match ($status) {
            'completed' => 'Uploaded to cloud',
            'not_uploaded' => 'Local only',
            default => 'Unknown',
        };
    }

    private function formatTimestamp(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)
                ->timezone(config('app.timezone'))
                ->format('Y-m-d H:i:s T');
        } catch (\Throwable) {
            return $value;
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function isSha256(?string $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value) === 1;
    }

    public function formatBytes(?int $bytes): string
    {
        if ($bytes === null || $bytes < 0) {
            return '—';
        }

        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 1).' KB';
        }

        if ($bytes < 1024 * 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 1).' MB';
        }

        return number_format($bytes / (1024 * 1024 * 1024), 2).' GB';
    }
}
