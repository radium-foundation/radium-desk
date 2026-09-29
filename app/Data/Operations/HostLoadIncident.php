<?php

namespace App\Data\Operations;

use Illuminate\Support\Carbon;

readonly class HostLoadIncident
{
    /**
     * @param  array{load1: float, load5: float, load15: float}  $load
     * @param  array{cpu: float, pid: int, command: string, classification: string}|null  $topProcess
     * @param  array{telegram: string, sent_at: string|null, error: string|null}  $notification
     */
    public function __construct(
        public string $id,
        public string $fingerprint,
        public string $status,
        public string $severity,
        public string $incidentType,
        public string $host,
        public string $project,
        public array $load,
        public int $durationSeconds,
        public ?array $topProcess,
        public string $classification,
        public string $action,
        public array $notification,
        public Carbon $detectedAt,
        public ?Carbon $resolvedAt,
        public string $alertKey,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            id: (string) ($payload['id'] ?? ''),
            fingerprint: (string) ($payload['fingerprint'] ?? ''),
            status: (string) ($payload['status'] ?? 'open'),
            severity: (string) ($payload['severity'] ?? 'elevated'),
            incidentType: (string) ($payload['incident_type'] ?? 'sustained_load'),
            host: (string) ($payload['host'] ?? ''),
            project: (string) ($payload['project'] ?? 'RadiumDesk'),
            load: is_array($payload['load'] ?? null) ? $payload['load'] : ['load1' => 0.0, 'load5' => 0.0, 'load15' => 0.0],
            durationSeconds: max(0, (int) ($payload['duration_seconds'] ?? 0)),
            topProcess: is_array($payload['top_process'] ?? null) ? $payload['top_process'] : null,
            classification: (string) ($payload['classification'] ?? 'unknown'),
            action: (string) ($payload['action'] ?? 'ALERT_ONLY'),
            notification: is_array($payload['notification'] ?? null) ? $payload['notification'] : ['telegram' => 'skipped', 'sent_at' => null, 'error' => null],
            detectedAt: Carbon::parse((string) ($payload['detected_at'] ?? now()->toIso8601String())),
            resolvedAt: isset($payload['resolved_at']) && $payload['resolved_at'] !== null
                ? Carbon::parse((string) $payload['resolved_at'])
                : null,
            alertKey: (string) ($payload['alert_key'] ?? 'host_load:elevated'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'fingerprint' => $this->fingerprint,
            'status' => $this->status,
            'severity' => $this->severity,
            'incident_type' => $this->incidentType,
            'host' => $this->host,
            'project' => $this->project,
            'load' => $this->load,
            'duration_seconds' => $this->durationSeconds,
            'top_process' => $this->topProcess,
            'classification' => $this->classification,
            'action' => $this->action,
            'notification' => $this->notification,
            'detected_at' => $this->detectedAt->toIso8601String(),
            'resolved_at' => $this->resolvedAt?->toIso8601String(),
            'alert_key' => $this->alertKey,
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
