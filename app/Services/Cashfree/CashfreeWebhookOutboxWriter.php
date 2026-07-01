<?php

namespace App\Services\Cashfree;

use App\Data\CashfreeWebhookDeferredContext;
use App\Enums\OutboxEventStatus;
use App\Models\OutboxEvent;

class CashfreeWebhookOutboxWriter
{
    public const EVENT_TYPE = 'cashfree.webhook.deferred_operation';

    public const AGGREGATE_TYPE = 'incident';

    /**
     * @var list<string>
     */
    private const OPERATIONS = [
        CashfreeWebhookDeferredOperationsService::OPERATION_AUTOMATION_MONITOR,
        CashfreeWebhookDeferredOperationsService::OPERATION_DASHBOARD_BROADCAST,
        CashfreeWebhookDeferredOperationsService::OPERATION_RADIUMBOX_ENRICHMENT,
    ];

    public function writeDeferredOperations(CashfreeWebhookDeferredContext $context): void
    {
        foreach (self::OPERATIONS as $operation) {
            $idempotencyKey = sprintf(
                'cashfree.webhook.deferred.%s.%d',
                $operation,
                $context->incidentId,
            );

            OutboxEvent::query()->firstOrCreate(
                ['idempotency_key' => $idempotencyKey],
                [
                    'event_type' => self::EVENT_TYPE,
                    'aggregate_type' => self::AGGREGATE_TYPE,
                    'aggregate_id' => $context->incidentId,
                    'payload' => [
                        'operation' => $operation,
                        'order_id' => $context->orderId,
                        'incident_id' => $context->incidentId,
                        'actor_id' => $context->actorId,
                    ],
                    'status' => OutboxEventStatus::Pending,
                    'attempts' => 0,
                    'available_at' => now(),
                ],
            );
        }
    }
}
