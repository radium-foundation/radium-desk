<?php

namespace App\Services\Platform\Alerts\Contributors;

use App\Contracts\Platform\PlatformAlertContributor;
use App\Data\Operations\HostLoadIncident;
use App\Data\Platform\PlatformAlert;
use App\Enums\PlatformAlertSeverity;
use App\Services\Operations\HostLoad\HostLoadIncidentStore;

/**
 * Open host-load incidents from the file-backed store — no live load probes.
 */
class HostLoadAlertContributor implements PlatformAlertContributor
{
    public function __construct(
        private readonly HostLoadIncidentStore $incidentStore,
    ) {}

    public function key(): string
    {
        return 'host_load';
    }

    public function label(): string
    {
        return 'Host Load';
    }

    public function sortOrder(): int
    {
        return 15;
    }

    public function alerts(): array
    {
        if (! (bool) config('host_load_watchdog.enabled', false)) {
            return [];
        }

        $open = $this->incidentStore->openIncidents();
        if ($open === []) {
            return [];
        }

        return array_map(
            fn (HostLoadIncident $incident): PlatformAlert => $this->toPlatformAlert($incident),
            $open,
        );
    }

    private function toPlatformAlert(HostLoadIncident $incident): PlatformAlert
    {
        $severity = $incident->severity === 'critical'
            ? PlatformAlertSeverity::Critical
            : PlatformAlertSeverity::Warning;

        $load = $incident->load;
        $summary = sprintf(
            'Load 1/5/15: %.2f / %.2f / %.2f — %s (%s)',
            (float) ($load['load1'] ?? 0.0),
            (float) ($load['load5'] ?? 0.0),
            (float) ($load['load15'] ?? 0.0),
            $incident->classification,
            $incident->action,
        );

        return new PlatformAlert(
            id: 'host_load:'.$incident->id,
            source: $this->key(),
            groupKey: 'host_load',
            title: $incident->severity === 'critical' ? 'Host Load (Critical)' : 'Host Load (Elevated)',
            summary: $summary,
            severity: $severity,
            status: 'open',
            lastUpdated: $incident->detectedAt,
            count: 1,
            link: route('admin.platform.index').'#platform-health',
            related: [
                [
                    'incident_id' => $incident->id,
                    'host' => $incident->host,
                    'duration_seconds' => $incident->durationSeconds,
                ],
            ],
        );
    }
}
