<?php

namespace App\Services\Operations\HostLoad;

use App\Data\Operations\HostLoadIncident;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class HostLoadIncidentStore
{
    private const FILENAME = 'host-load-incidents.json';

    public function openIncidents(): array
    {
        return array_values(array_filter(
            $this->allIncidents(),
            fn (HostLoadIncident $incident): bool => $incident->isOpen(),
        ));
    }

    /**
     * @return list<HostLoadIncident>
     */
    public function allIncidents(): array
    {
        $payload = $this->read();
        $incidents = [];

        foreach ($payload['incidents'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $incidents[] = HostLoadIncident::fromArray($row);
        }

        return $incidents;
    }

    public function find(string $incidentId): ?HostLoadIncident
    {
        foreach ($this->allIncidents() as $incident) {
            if ($incident->id === $incidentId) {
                return $incident;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function openOrUpdate(array $context): HostLoadIncident
    {
        $payload = $this->read();
        $incidents = is_array($payload['incidents'] ?? null) ? $payload['incidents'] : [];
        $fingerprint = (string) ($context['fingerprint'] ?? '');
        $alertKey = (string) ($context['alert_key'] ?? 'host_load:elevated');

        $existingIndex = null;
        foreach ($incidents as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            if (($row['status'] ?? '') === 'open' && ($row['alert_key'] ?? '') === $alertKey) {
                $existingIndex = $index;
                break;
            }
        }

        $now = now();
        if ($existingIndex !== null) {
            $existing = HostLoadIncident::fromArray($incidents[$existingIndex]);
            unset($context['notification']);
            $incidents[$existingIndex] = array_merge($existing->toArray(), $context, [
                'id' => $existing->id,
                'detected_at' => $existing->detectedAt->toIso8601String(),
                'status' => 'open',
                'fingerprint' => $fingerprint !== '' ? $fingerprint : $existing->fingerprint,
                'notification' => $existing->notification,
            ]);
        } else {
            $incidents[] = array_merge($context, [
                'id' => $this->generateId($now),
                'status' => 'open',
                'detected_at' => $now->toIso8601String(),
                'resolved_at' => null,
                'notification' => $context['notification'] ?? ['telegram' => 'pending', 'sent_at' => null, 'error' => null],
            ]);
        }

        $payload['incidents'] = $this->prune($incidents);
        $this->write($payload);

        return HostLoadIncident::fromArray($existingIndex !== null ? $incidents[$existingIndex] : $incidents[array_key_last($incidents)]);
    }

    public function recordNotification(string $incidentId, string $telegramStatus, ?string $error = null): void
    {
        $payload = $this->read();
        $incidents = is_array($payload['incidents'] ?? null) ? $payload['incidents'] : [];

        foreach ($incidents as $index => $row) {
            if (! is_array($row) || ($row['id'] ?? '') !== $incidentId) {
                continue;
            }

            $incidents[$index]['notification'] = [
                'telegram' => $telegramStatus,
                'sent_at' => $telegramStatus === 'sent' ? now()->toIso8601String() : ($row['notification']['sent_at'] ?? null),
                'error' => $error,
            ];
            break;
        }

        $payload['incidents'] = $incidents;
        $this->write($payload);
    }

    public function resolveOpenByAlertKeys(array $alertKeys): int
    {
        if ($alertKeys === []) {
            return 0;
        }

        $payload = $this->read();
        $incidents = is_array($payload['incidents'] ?? null) ? $payload['incidents'] : [];
        $resolved = 0;
        $now = now()->toIso8601String();

        foreach ($incidents as $index => $row) {
            if (! is_array($row) || ($row['status'] ?? '') !== 'open') {
                continue;
            }

            if (! in_array((string) ($row['alert_key'] ?? ''), $alertKeys, true)) {
                continue;
            }

            $incidents[$index]['status'] = 'resolved';
            $incidents[$index]['resolved_at'] = $now;
            $resolved++;
        }

        if ($resolved > 0) {
            $payload['incidents'] = $this->prune($incidents);
            $this->write($payload);
        }

        return $resolved;
    }

    public static function clearForTests(): void
    {
        $path = self::path();

        if (File::exists($path)) {
            File::delete($path);
        }
    }

    /**
     * @return array{incidents: list<array<string, mixed>>}
     */
    private function read(): array
    {
        $path = self::path();

        if (! File::exists($path)) {
            return ['incidents' => []];
        }

        $decoded = json_decode((string) File::get($path), true);

        if (! is_array($decoded)) {
            return ['incidents' => []];
        }

        return [
            'incidents' => is_array($decoded['incidents'] ?? null) ? $decoded['incidents'] : [],
        ];
    }

    /**
     * @param  array{incidents: list<array<string, mixed>>}  $payload
     */
    private function write(array $payload): void
    {
        $path = self::path();
        $directory = dirname($path);

        if (! File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        $temporary = $path.'.tmp';

        File::put($temporary, $encoded);
        File::move($temporary, $path);
    }

    /**
     * @param  list<array<string, mixed>>  $incidents
     * @return list<array<string, mixed>>
     */
    private function prune(array $incidents): array
    {
        $retentionDays = max(1, (int) config('host_load_watchdog.incident_retention_days', 30));
        $cutoff = now()->subDays($retentionDays);

        return array_values(array_filter($incidents, function (array $row) use ($cutoff): bool {
            $timestamp = (string) ($row['resolved_at'] ?? $row['detected_at'] ?? '');
            if ($timestamp === '') {
                return true;
            }

            return Carbon::parse($timestamp)->greaterThanOrEqualTo($cutoff);
        }));
    }

    private function generateId(Carbon $at): string
    {
        return 'HL-'.$at->format('Ymd-His').'-'.Str::lower(Str::random(6));
    }

    private static function path(): string
    {
        if (app()->runningUnitTests()) {
            return storage_path('framework/testing/'.self::FILENAME);
        }

        return storage_path('framework/platform-health/'.self::FILENAME);
    }
}
