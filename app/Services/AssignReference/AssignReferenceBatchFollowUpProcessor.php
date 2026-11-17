<?php

namespace App\Services\AssignReference;

use App\Enums\OutboxEventStatus;
use App\Jobs\ProcessAssignReferenceBatchFollowUpJob;
use App\Models\OutboxEvent;
use App\Models\User;
use App\Services\AssignReferenceBatchCoalescer;
use App\Services\DashboardBroadcastService;
use App\Services\SettingService;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AssignReferenceBatchFollowUpProcessor
{
    public function __construct(
        private readonly AssignReferenceBatchCoalescer $batchCoalescer,
        private readonly SettingService $settingService,
        private readonly DashboardBroadcastService $dashboardBroadcastService,
    ) {}

    public function process(OutboxEvent $event): void
    {
        if ($event->event_type !== AssignReferenceBatchFollowUpOutboxWriter::EVENT_TYPE) {
            throw new \InvalidArgumentException('Unexpected outbox event type for batch follow-up.');
        }

        if ($event->status === OutboxEventStatus::Completed) {
            return;
        }

        $payload = $event->payload ?? [];
        $batchId = (string) ($payload['batch_id'] ?? '');
        $phaseStartedAt = microtime(true);

        Log::info('bulk_assign.batch.follow_up.started', [
            'batch_id' => $batchId,
            'outbox_event_id' => $event->id,
        ]);

        try {
            $this->batchCoalescer->flushCommunicationsFromSnapshot(
                pendingNotifications: $payload['pending_notifications'] ?? [],
                pendingDriverGuides: $payload['pending_driver_guides'] ?? [],
                driverGuideActorId: isset($payload['driver_guide_actor_id'])
                    ? (int) $payload['driver_guide_actor_id']
                    : null,
                settingService: $this->settingService,
                batchId: $batchId,
            );

            $communicationsMs = (int) round((microtime(true) - $phaseStartedAt) * 1000);

            Log::info('bulk_assign.batch.follow_up.communications', [
                'batch_id' => $batchId,
                'outbox_event_id' => $event->id,
                'duration_ms' => $communicationsMs,
            ]);

            if (($payload['kpi_coalesce_pending'] ?? false) === true) {
                $actorId = (int) ($payload['actor_id'] ?? 0);
                $actor = $actorId > 0 ? User::query()->find($actorId) : null;

                if ($actor !== null) {
                    $this->dashboardBroadcastService->beginKpiCoalesce($actor);
                    $this->dashboardBroadcastService->flushKpiCoalesce();
                }
            }

            $event->update([
                'status' => OutboxEventStatus::Completed,
                'processed_at' => now(),
                'last_error' => null,
            ]);

            Log::info('bulk_assign.batch.follow_up.finished', [
                'batch_id' => $batchId,
                'outbox_event_id' => $event->id,
                'duration_ms' => (int) round((microtime(true) - $phaseStartedAt) * 1000),
            ]);
        } catch (Throwable $exception) {
            Log::error('bulk_assign.batch.follow_up.failed', [
                'batch_id' => $batchId,
                'outbox_event_id' => $event->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    public function dispatchFromOutbox(OutboxEvent $event): void
    {
        ProcessAssignReferenceBatchFollowUpJob::dispatch($event->id);
    }
}
