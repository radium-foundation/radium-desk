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

/**
 * Processes durable batch assign follow-up (admin DB notifications, driver-guide job dispatch, KPI coalesce).
 *
 * Outbox completion means operational dispatches were enqueued — not customer WhatsApp/email delivery.
 * Customer channel outcomes remain on communication-action audits and driver-guide job retries.
 */
final class AssignReferenceBatchFollowUpProcessor
{
    public const PROCESSED_SCOPE = 'operational_dispatches_enqueued';

    private const PHASE_ADMIN_NOTIFICATIONS = 'admin_notifications';

    private const PHASE_DRIVER_GUIDES = 'driver_guides';

    private const PHASE_KPI = 'kpi_coalesce';

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
        $phases = is_array($payload['phases'] ?? null) ? $payload['phases'] : [];
        $phaseStartedAt = microtime(true);

        Log::info('bulk_assign.batch.follow_up.started', [
            'batch_id' => $batchId,
            'outbox_event_id' => $event->id,
            'phases_completed' => array_keys(array_filter($phases)),
        ]);

        try {
            if (! ($phases[self::PHASE_ADMIN_NOTIFICATIONS] ?? false)) {
                $this->batchCoalescer->flushAdminNotificationsFromSnapshot(
                    pendingNotifications: $payload['pending_notifications'] ?? [],
                    settingService: $this->settingService,
                    batchId: $batchId,
                );
                $this->markPhaseComplete($event, $payload, $phases, self::PHASE_ADMIN_NOTIFICATIONS);
                $event->refresh();
                $payload = $event->payload ?? [];
                $phases = is_array($payload['phases'] ?? null) ? $payload['phases'] : [];
            }

            if (! ($phases[self::PHASE_DRIVER_GUIDES] ?? false)) {
                $this->batchCoalescer->flushDriverGuidesFromSnapshot(
                    pendingDriverGuides: $payload['pending_driver_guides'] ?? [],
                    driverGuideActorId: isset($payload['driver_guide_actor_id'])
                        ? (int) $payload['driver_guide_actor_id']
                        : null,
                    batchId: $batchId,
                );
                $this->markPhaseComplete($event, $payload, $phases, self::PHASE_DRIVER_GUIDES);
                $event->refresh();
                $payload = $event->payload ?? [];
                $phases = is_array($payload['phases'] ?? null) ? $payload['phases'] : [];
            }

            if (($payload['kpi_coalesce_pending'] ?? false) === true
                && ! ($phases[self::PHASE_KPI] ?? false)) {
                $actorId = (int) ($payload['actor_id'] ?? 0);
                $actor = $actorId > 0 ? User::query()->find($actorId) : null;

                if ($actor !== null) {
                    $this->dashboardBroadcastService->beginKpiCoalesce($actor);
                    $this->dashboardBroadcastService->flushKpiCoalesce();
                }

                $this->markPhaseComplete($event, $payload, $phases, self::PHASE_KPI);
                $event->refresh();
                $payload = $event->payload ?? [];
            }

            $payload['processed_scope'] = self::PROCESSED_SCOPE;
            $payload['phases'] = $phases;

            $event->update([
                'status' => OutboxEventStatus::Completed,
                'processed_at' => now(),
                'last_error' => null,
                'payload' => $payload,
            ]);

            Log::info('bulk_assign.batch.follow_up.finished', [
                'batch_id' => $batchId,
                'outbox_event_id' => $event->id,
                'processed_scope' => self::PROCESSED_SCOPE,
                'duration_ms' => (int) round((microtime(true) - $phaseStartedAt) * 1000),
            ]);
        } catch (Throwable $exception) {
            Log::error('bulk_assign.batch.follow_up.failed', [
                'batch_id' => $batchId,
                'outbox_event_id' => $event->id,
                'phases_completed' => array_keys(array_filter($phases)),
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

    /**
     * @param  array<string, bool>  $phases
     */
    private function markPhaseComplete(
        OutboxEvent $event,
        array $payload,
        array $phases,
        string $phase,
    ): void {
        $phases[$phase] = true;
        $payload['phases'] = $phases;

        $event->update([
            'payload' => $payload,
        ]);

        Log::info('bulk_assign.batch.follow_up.phase', [
            'batch_id' => $payload['batch_id'] ?? '',
            'outbox_event_id' => $event->id,
            'phase' => $phase,
        ]);
    }
}
