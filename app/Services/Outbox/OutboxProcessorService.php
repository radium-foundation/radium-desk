<?php

namespace App\Services\Outbox;

use App\Enums\OutboxEventStatus;
use App\Models\OutboxEvent;
use App\Models\InteraktWebhookLog;
use App\Services\Cashfree\CashfreeWebhookDeferredOperationsService;
use App\Services\Cashfree\CashfreeWebhookOutboxWriter;
use App\Services\Interakt\InteraktOutboundOutboxWriter;
use App\Services\Interakt\InteraktOutboundProcessorService;
use App\Services\Interakt\InteraktWebhookOutboxWriter;
use App\Services\Interakt\InteraktWebhookProcessorService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class OutboxProcessorService
{
    public const MAX_ATTEMPTS = 5;

    /** @var list<int> */
    public const BACKOFF_SECONDS = [30, 120, 600, 1800];

    private const STALE_PROCESSING_MINUTES = 5;

    public function __construct(
        private readonly CashfreeWebhookDeferredOperationsService $cashfreeDeferredOperationsService,
        private readonly InteraktWebhookProcessorService $interaktWebhookProcessorService,
        private readonly InteraktOutboundProcessorService $interaktOutboundProcessorService,
    ) {}

    public function process(?int $limit = null): int
    {
        $this->recoverStaleProcessingEvents();

        $processed = 0;

        while ($limit === null || $processed < $limit) {
            $event = $this->claimNextEvent();

            if ($event === null) {
                break;
            }

            $this->processClaimedEvent($event);
            $processed++;
        }

        return $processed;
    }

    private function recoverStaleProcessingEvents(): void
    {
        OutboxEvent::query()
            ->where('status', OutboxEventStatus::Processing)
            ->where('updated_at', '<', now()->subMinutes(self::STALE_PROCESSING_MINUTES))
            ->update(['status' => OutboxEventStatus::Pending]);
    }

    private function claimNextEvent(): ?OutboxEvent
    {
        return DB::transaction(function (): ?OutboxEvent {
            $event = OutboxEvent::query()
                ->where('status', OutboxEventStatus::Pending)
                ->where('available_at', '<=', now())
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($event === null) {
                return null;
            }

            $event->update([
                'status' => OutboxEventStatus::Processing,
                'attempts' => $event->attempts + 1,
            ]);

            return $event->fresh();
        });
    }

    private function processClaimedEvent(OutboxEvent $event): void
    {
        try {
            $this->dispatch($event);

            $event->update([
                'status' => OutboxEventStatus::Completed,
                'processed_at' => now(),
                'last_error' => null,
            ]);
        } catch (Throwable $exception) {
            $this->markFailure($event, $exception);

            Log::error('[Outbox] Event processing failed.', [
                'outbox_event_id' => $event->id,
                'event_type' => $event->event_type,
                'aggregate_type' => $event->aggregate_type,
                'aggregate_id' => $event->aggregate_id,
                'attempts' => $event->attempts,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function dispatch(OutboxEvent $event): void
    {
        match ($event->event_type) {
            CashfreeWebhookOutboxWriter::EVENT_TYPE => $this->dispatchCashfreeDeferredOperation($event),
            InteraktWebhookOutboxWriter::EVENT_TYPE => $this->dispatchInteraktWebhookProcessing($event),
            InteraktOutboundOutboxWriter::EVENT_TYPE => $this->dispatchInteraktTemplateSend($event),
            default => throw new RuntimeException('Unknown outbox event type: '.$event->event_type),
        };
    }

    private function dispatchInteraktWebhookProcessing(OutboxEvent $event): void
    {
        $payload = $event->payload ?? [];
        $webhookLogId = (int) ($payload['webhook_log_id'] ?? 0);

        if ($webhookLogId <= 0) {
            throw new RuntimeException('Interakt outbox event is missing webhook_log_id.');
        }

        $webhookLog = InteraktWebhookLog::query()->find($webhookLogId);

        if ($webhookLog === null) {
            throw new RuntimeException('Interakt webhook log not found: '.$webhookLogId);
        }

        $this->interaktWebhookProcessorService->process($webhookLog);
    }

    private function dispatchInteraktTemplateSend(OutboxEvent $event): void
    {
        $payload = $event->payload ?? [];
        $dispatchId = (int) ($payload['dispatch_id'] ?? 0);

        if ($dispatchId <= 0) {
            throw new RuntimeException('Interakt outbound event is missing dispatch_id.');
        }

        $this->interaktOutboundProcessorService->processDispatch($dispatchId);
    }

    private function dispatchCashfreeDeferredOperation(OutboxEvent $event): void
    {
        $payload = $event->payload ?? [];
        $operation = $payload['operation'] ?? null;

        if (! is_string($operation) || $operation === '') {
            throw new RuntimeException('Cashfree outbox event is missing operation.');
        }

        $this->cashfreeDeferredOperationsService->executeOperation(
            operation: $operation,
            orderId: (int) ($payload['order_id'] ?? 0),
            incidentId: (int) ($payload['incident_id'] ?? 0),
            actorId: (int) ($payload['actor_id'] ?? 0),
        );
    }

    private function markFailure(OutboxEvent $event, Throwable $exception): void
    {
        $attempts = $event->attempts;
        $message = $exception->getMessage();

        if ($attempts >= self::MAX_ATTEMPTS) {
            $event->update([
                'status' => OutboxEventStatus::Failed,
                'last_error' => $message,
            ]);

            return;
        }

        $event->update([
            'status' => OutboxEventStatus::Pending,
            'available_at' => $this->nextAvailableAt($attempts),
            'last_error' => $message,
        ]);
    }

    private function nextAvailableAt(int $attempts): Carbon
    {
        $index = max(0, min($attempts - 1, count(self::BACKOFF_SECONDS) - 1));

        return now()->addSeconds(self::BACKOFF_SECONDS[$index]);
    }
}
