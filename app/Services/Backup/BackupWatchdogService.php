<?php

namespace App\Services\Backup;

use App\Data\Operations\ProductionCriticalAlert;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

/**
 * Backup health signals for ProductionWatchdogService.
 *
 * Reads the sanitized last-run-status.json written by bin/backup-schedule.sh.
 * Never executes backup commands or exposes secrets, credentials, or paths.
 */
class BackupWatchdogService
{
    private const BACKUP_ID_PATTERN = '/^[0-9]{8}T[0-9]{6}Z$/';

    private const ALLOWED_OUTCOMES = [
        'success',
        'local_failure',
        'cloud_upload_failure',
        'lock_overlap',
    ];

    /**
     * @return list<ProductionCriticalAlert>
     */
    public function collectAlerts(): array
    {
        if (! (bool) config('backup.watchdog.enabled', true)) {
            return [];
        }

        $status = $this->readStatus();
        $alerts = $this->alertsFromLastRun($status);

        $staleAlert = $this->staleAlert($status);
        if ($staleAlert !== null) {
            $alerts[] = $staleAlert;
        }

        return $alerts;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function readStatus(): ?array
    {
        $path = $this->statusPath();

        if ($path === null || ! is_file($path) || ! is_readable($path)) {
            return null;
        }

        try {
            $data = json_decode((string) File::get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (! is_array($data)) {
            return null;
        }

        if (($data['watchdog_accessible'] ?? true) === false) {
            return null;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>|null  $status
     * @return list<ProductionCriticalAlert>
     */
    private function alertsFromLastRun(?array $status): array
    {
        if ($status === null) {
            return [];
        }

        $outcome = (string) ($status['outcome'] ?? '');

        if (! in_array($outcome, self::ALLOWED_OUTCOMES, true) || $outcome === 'success') {
            return [];
        }

        return match ($outcome) {
            'local_failure' => [
                $this->buildRunAlert(
                    key: 'backup:local_failure',
                    message: $this->runFailureMessage(
                        'Local encrypted backup staging failed.',
                        $status,
                    ),
                    status: $status,
                ),
            ],
            'cloud_upload_failure' => [
                $this->buildRunAlert(
                    key: 'backup:cloud_upload_failure',
                    message: $this->runFailureMessage(
                        'Cloud backup upload failed after local staging succeeded.',
                        $status,
                    ),
                    status: $status,
                ),
            ],
            'lock_overlap' => [
                $this->buildRunAlert(
                    key: 'backup:lock_overlap',
                    message: 'Scheduled backup was skipped because another backup run still holds the schedule lock.',
                    status: $status,
                ),
            ],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>|null  $status
     */
    private function staleAlert(?array $status): ?ProductionCriticalAlert
    {
        $lastSuccessAt = $this->lastSuccessfulBackupAt($status);

        if ($lastSuccessAt === null) {
            if ($status !== null && in_array((string) ($status['outcome'] ?? ''), [
                'local_failure',
                'cloud_upload_failure',
                'lock_overlap',
            ], true)) {
                return null;
            }

            return new ProductionCriticalAlert(
                key: 'backup:stale',
                label: 'Backup',
                message: 'No successful encrypted backup has been recorded yet.',
            );
        }

        $staleHours = max(1, (int) config('backup.watchdog.stale_hours', 26));

        if ($lastSuccessAt->greaterThan(now()->subHours($staleHours))) {
            return null;
        }

        $hoursAgo = max(1, (int) $lastSuccessAt->diffInHours(now()));

        return new ProductionCriticalAlert(
            key: 'backup:stale',
            label: 'Backup',
            message: sprintf(
                'Latest successful backup is %d hour(s) old (threshold %d hour(s)).',
                $hoursAgo,
                $staleHours,
            ),
            incidentIdentity: $lastSuccessAt->toIso8601String(),
        );
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function buildRunAlert(string $key, string $message, array $status): ProductionCriticalAlert
    {
        $backupId = trim((string) ($status['backup_id'] ?? ''));
        $summary = trim((string) ($status['error_summary'] ?? ''));
        $identity = $backupId;

        if ($summary !== '') {
            $identity = ($identity !== '' ? $identity.'|' : '').$summary;
        }

        if ($identity === '') {
            $identity = (string) ($status['generated_at'] ?? now()->toIso8601String());
        }

        return new ProductionCriticalAlert(
            key: $key,
            label: 'Backup',
            message: $message,
            incidentIdentity: $identity,
        );
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function runFailureMessage(string $prefix, array $status): string
    {
        $summary = trim((string) ($status['error_summary'] ?? ''));

        if ($summary === '') {
            return $prefix;
        }

        return $prefix.' '.$summary;
    }

    /**
     * @param  array<string, mixed>|null  $status
     */
    private function lastSuccessfulBackupAt(?array $status): ?Carbon
    {
        if ($status !== null && ($status['outcome'] ?? '') === 'success') {
            $fromStatus = $this->timestampFromStatus($status);

            if ($fromStatus !== null) {
                return $fromStatus;
            }
        }

        $summary = app(BackupStatusService::class)->summary();
        $latest = $summary['latest'] ?? null;

        if (! is_array($latest)) {
            return null;
        }

        $backupId = trim((string) ($latest['backup_id'] ?? ''));

        return $this->timestampFromBackupId($backupId);
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function timestampFromStatus(array $status): ?Carbon
    {
        $backupId = trim((string) ($status['backup_id'] ?? ''));

        if ($backupId !== '') {
            $fromId = $this->timestampFromBackupId($backupId);

            if ($fromId !== null) {
                return $fromId;
            }
        }

        $generatedAt = trim((string) ($status['generated_at'] ?? ''));

        if ($generatedAt === '') {
            return null;
        }

        try {
            return Carbon::parse($generatedAt)->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    private function timestampFromBackupId(string $backupId): ?Carbon
    {
        if (! preg_match(self::BACKUP_ID_PATTERN, $backupId)) {
            return null;
        }

        $iso = sprintf(
            '%s-%s-%sT%s:%s:%sZ',
            substr($backupId, 0, 4),
            substr($backupId, 4, 2),
            substr($backupId, 6, 2),
            substr($backupId, 9, 2),
            substr($backupId, 11, 2),
            substr($backupId, 13, 2),
        );

        try {
            return Carbon::createFromFormat('Y-m-d\TH:i:s\Z', $iso, 'UTC');
        } catch (\Throwable) {
            return null;
        }
    }

    private function statusPath(): ?string
    {
        $path = trim((string) config('backup.watchdog.status_path', ''));

        return $path === '' ? null : $path;
    }
}
