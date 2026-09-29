<?php

namespace App\Services\Operations\HostLoad;

use App\Data\Operations\HostLoadIncident;
use App\Data\Operations\IraCommunicationInput;
use App\Data\Operations\ProductionCriticalAlert;
use App\Enums\IraNotificationStatus;
use App\Enums\IraNotificationType;
use App\Services\Operations\IraCommunicationService;
use App\Services\Operations\WatchdogCriticalAlertGate;
use Illuminate\Support\Facades\Log;

class HostLoadWatchdogService
{
    public function __construct(
        private readonly HostLoadProbe $probe,
        private readonly HostLoadSampleTracker $sampleTracker,
        private readonly CpuProcessSampleReader $sampleReader,
        private readonly HostLoadProcessClassifier $classifier,
        private readonly HostLoadIncidentStore $incidentStore,
        private readonly IraCommunicationService $communicationService,
        private readonly WatchdogCriticalAlertGate $alertGate,
    ) {}

    /**
     * @return array{
     *     status: string,
     *     level: string,
     *     incident_id: string|null,
     *     notified: bool
     * }
     */
    public function monitor(): array
    {
        if (! (bool) config('host_load_watchdog.enabled', false)) {
            return [
                'status' => 'disabled',
                'level' => 'disabled',
                'incident_id' => null,
                'notified' => false,
            ];
        }

        try {
            $load = $this->probe->loadAverages();
            $evaluation = $this->sampleTracker->record($load['load1']);
            $sampler = $this->sampleReader->latestSample();

            if ($evaluation['level'] === 'normal') {
                $this->resolveIncidents();

                return [
                    'status' => 'normal',
                    'level' => 'normal',
                    'incident_id' => null,
                    'notified' => false,
                ];
            }

            $topProcess = $sampler['top_process'] ?? null;
            $classification = $this->classifier->classify($topProcess);

            if ($evaluation['level'] === 'critical') {
                $this->incidentStore->resolveOpenByAlertKeys(['host_load:elevated']);
            }

            $alert = $this->buildAlert($evaluation, $load, $sampler, $topProcess, $classification);
            $this->alertGate->syncResolved([$alert]);

            $incident = $this->incidentStore->openOrUpdate($this->incidentPayload($alert, $evaluation, $load, $topProcess, $classification));
            $notified = false;

            if ($this->alertGate->shouldNotify($alert)) {
                $notified = $this->dispatchTelegram($alert, $incident);
                if ($notified) {
                    $this->alertGate->markNotified($alert);
                    $this->incidentStore->recordNotification($incident->id, 'sent');
                } else {
                    $this->incidentStore->recordNotification($incident->id, 'failed', 'Telegram delivery failed');
                }
            } else {
                $this->incidentStore->recordNotification($incident->id, 'suppressed');
            }

            return [
                'status' => 'alert',
                'level' => (string) $evaluation['level'],
                'incident_id' => $incident->id,
                'notified' => $notified,
            ];
        } catch (\Throwable $exception) {
            Log::warning('host_load_watchdog.monitor_failed', [
                'message' => $exception->getMessage(),
            ]);

            return [
                'status' => 'failed',
                'level' => 'unknown',
                'incident_id' => null,
                'notified' => false,
            ];
        }
    }

    private function resolveIncidents(): void
    {
        $this->incidentStore->resolveOpenByAlertKeys([
            'host_load:elevated',
            'host_load:critical',
        ]);

        $this->alertGate->syncResolved([]);
    }

    /**
     * @param  array{
     *     level: string,
     *     duration_seconds: int,
     *     consecutive_elevated: int,
     *     consecutive_critical: int
     * }  $evaluation
     * @param  array{load1: float, load5: float, load15: float}  $load
     * @param  array<string, mixed>|null  $sampler
     * @param  array{cpu: float, pid: int, command: string}|null  $topProcess
     */
    private function buildAlert(
        array $evaluation,
        array $load,
        ?array $sampler,
        ?array $topProcess,
        string $classification,
    ): ProductionCriticalAlert {
        $level = (string) $evaluation['level'];
        $alertKey = $level === 'critical' ? 'host_load:critical' : 'host_load:elevated';
        $label = $level === 'critical' ? 'Host Load (Critical)' : 'Host Load (Elevated)';
        $host = (string) config('host_load_watchdog.host_name', gethostname());
        $project = (string) config('host_load_watchdog.project_name', 'RadiumDesk');
        $durationMinutes = max(1, (int) ceil(((int) $evaluation['duration_seconds']) / 60));
        $samplerLoads = $sampler ?? $load;

        $processSummary = 'unknown';
        if ($topProcess !== null) {
            $processSummary = sprintf(
                '%s (PID %d, %.1f%% CPU)',
                $topProcess['command'],
                $topProcess['pid'],
                $topProcess['cpu'],
            );
        }

        $message = implode("\n", array_filter([
            'Sustained host load detected ('.$level.').',
            'Host: '.$host,
            'Project: '.$project,
            'Incident type: sustained_load',
            sprintf(
                'Load (1/5/15): %.2f / %.2f / %.2f',
                (float) $samplerLoads['load1'],
                (float) $samplerLoads['load5'],
                (float) $samplerLoads['load15'],
            ),
            'Duration: ~'.$durationMinutes.' minute(s)',
            'Top process: '.$processSummary,
            'Classification: '.$classification,
            'Action: ALERT_ONLY',
            'Timestamp: '.now()->toIso8601String(),
        ]));

        return new ProductionCriticalAlert(
            key: $alertKey,
            label: $label,
            message: $message,
            affectedCount: $level === 'critical' ? 100 : 1,
            incidentIdentity: hash('sha256', $alertKey.'|'.$classification.'|'.$processSummary),
        );
    }

    /**
     * @param  array{
     *     level: string,
     *     duration_seconds: int
     * }  $evaluation
     * @param  array{load1: float, load5: float, load15: float}  $load
     * @param  array{cpu: float, pid: int, command: string}|null  $topProcess
     * @return array<string, mixed>
     */
    private function incidentPayload(
        ProductionCriticalAlert $alert,
        array $evaluation,
        array $load,
        ?array $topProcess,
        string $classification,
    ): array {
        return [
            'fingerprint' => $alert->fingerprint(),
            'severity' => (string) $evaluation['level'],
            'incident_type' => 'sustained_load',
            'host' => (string) config('host_load_watchdog.host_name', gethostname()),
            'project' => (string) config('host_load_watchdog.project_name', 'RadiumDesk'),
            'load' => $load,
            'duration_seconds' => (int) $evaluation['duration_seconds'],
            'top_process' => $topProcess,
            'classification' => $classification,
            'action' => 'ALERT_ONLY',
            'alert_key' => $alert->key,
            'notification' => ['telegram' => 'pending', 'sent_at' => null, 'error' => null],
        ];
    }

    private function dispatchTelegram(ProductionCriticalAlert $alert, HostLoadIncident $incident): bool
    {
        $message = "Incident ID: {$incident->id}\n".$alert->message;

        $delivered = $this->communicationService->dispatch(new IraCommunicationInput(
            event: IraNotificationType::CriticalSystemAlert,
            context: array_merge($alert->toContext(), [
                'incident_id' => $incident->id,
                'message' => $message,
            ]),
        ));

        foreach ($delivered as $notification) {
            if ($notification->status === IraNotificationStatus::Sent) {
                return true;
            }
        }

        return false;
    }
}
