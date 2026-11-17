<?php

namespace App\Services\AssignReference;

use App\Enums\OutboxEventStatus;
use App\Models\OutboxEvent;
use Illuminate\Support\Carbon;

/**
 * Durable registration for batch assign reference communications and dashboard follow-up.
 */
final class AssignReferenceBatchFollowUpOutboxWriter
{
    public const EVENT_TYPE = 'assign_reference.batch_follow_up';

    public const AGGREGATE_TYPE = 'assign_reference_batch';

    /**
     * @param  list<array{order_id: int, transaction_id: string, actor_id: int}>  $pendingNotifications
     * @param  list<array{order_id: int, service_reference: string}>  $pendingDriverGuides
     * @param  list<int>  $succeededIncidentIds
     */
    public function enqueue(
        string $batchId,
        int $actorId,
        array $pendingNotifications,
        array $pendingDriverGuides,
        array $succeededIncidentIds,
        ?Carbon $availableAt = null,
    ): OutboxEvent {
        $idempotencyKey = self::idempotencyKey($batchId);

        $payload = [
            'batch_id' => $batchId,
            'actor_id' => $actorId,
            'pending_notifications' => $pendingNotifications,
            'pending_driver_guides' => $pendingDriverGuides,
            'driver_guide_actor_id' => $actorId,
            'succeeded_incident_ids' => array_values(array_unique(array_map('intval', $succeededIncidentIds))),
            'kpi_coalesce_pending' => $succeededIncidentIds !== [],
        ];

        $existing = OutboxEvent::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            if ($existing->status === OutboxEventStatus::Completed) {
                return $existing;
            }

            $existing->update([
                'event_type' => self::EVENT_TYPE,
                'aggregate_type' => self::AGGREGATE_TYPE,
                'aggregate_id' => 0,
                'payload' => $payload,
                'status' => OutboxEventStatus::Pending,
                'attempts' => $existing->status === OutboxEventStatus::Failed ? 0 : $existing->attempts,
                'available_at' => $availableAt ?? now(),
                'last_error' => null,
                'processed_at' => null,
            ]);

            return $existing->fresh() ?? $existing;
        }

        return OutboxEvent::query()->create([
            'idempotency_key' => $idempotencyKey,
            'event_type' => self::EVENT_TYPE,
            'aggregate_type' => self::AGGREGATE_TYPE,
            'aggregate_id' => 0,
            'payload' => $payload,
            'status' => OutboxEventStatus::Pending,
            'attempts' => 0,
            'available_at' => $availableAt ?? now(),
        ]);
    }

    public static function idempotencyKey(string $batchId): string
    {
        return 'assign_reference.batch_follow_up:'.$batchId;
    }
}
