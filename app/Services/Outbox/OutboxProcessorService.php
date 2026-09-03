<?php

namespace App\Services\Outbox;

use App\Enums\OutboxEventStatus;
use App\Models\BonvoiceWebhookLog;
use App\Models\IncomingEmailMessage;
use App\Models\InteraktWebhookLog;
use App\Models\OutboxEvent;
use App\Services\Bonvoice\BonvoiceIncomingCallLatency;
use App\Services\Bonvoice\BonvoiceWebhookOutboxWriter;
use App\Services\Bonvoice\BonvoiceWebhookProcessorService;
use App\Services\Cashfree\CashfreeWebhookDeferredOperationsService;
use App\Services\Cashfree\CashfreeWebhookOutboxWriter;
use App\Services\IncomingEmail\IncomingEmailOutboxWriter;
use App\Services\IncomingEmail\IncomingEmailProcessorService;
use App\Services\Interakt\InteraktFlowWebhookOutboxWriter;
use App\Services\Interakt\InteraktFlowWebhookProcessorService;
use App\Services\Interakt\InteraktOutboundOutboxWriter;
use App\Services\Interakt\InteraktOutboundProcessorService;
use App\Services\Interakt\InteraktWebhookOutboxWriter;
use App\Services\Interakt\InteraktWebhookProcessorService;
use App\Services\StatutoryInvoice\EInvoiceOutboxWriter;
use App\Services\StatutoryInvoice\EInvoiceProcessor;
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
        private readonly InteraktFlowWebhookProcessorService $interaktFlowWebhookProcessorService,
        private readonly InteraktOutboundProcessorService $interaktOutboundProcessorService,
        private readonly BonvoiceWebhookProcessorService $bonvoiceWebhookProcessorService,
        private readonly IncomingEmailProcessorService $incomingEmailProcessorService,
        private readonly BonvoiceIncomingCallLatency $incomingCallLatency,
        private readonly EInvoiceProcessor $einvoiceProcessor,
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

            $this->processClaimedEvent($event, $processed);
            $processed++;
        }

        return $processed;
    }

    public function processAggregate(string $aggregateType, int $aggregateId): void
    {
        $this->recoverStaleProcessingEvents();

        // Claim every available Pending row for this aggregate in one lock
        // transaction so global process() cannot steal siblings mid-drain
        // (Cashfree deferred triples: monitor → dashboard_broadcast → enrichment).
        $events = DB::transaction(function () use ($aggregateType, $aggregateId): array {
            $pending = OutboxEvent::query()
                ->where('aggregate_type', $aggregateType)
                ->where('aggregate_id', $aggregateId)
                ->where('status', OutboxEventStatus::Pending)
                ->where('available_at', '<=', now())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($pending->isEmpty()) {
                return [];
            }

            $claimed = [];

            foreach ($pending as $event) {
                $event->update([
                    'status' => OutboxEventStatus::Processing,
                    'attempts' => $event->attempts + 1,
                ]);

                $fresh = $event->fresh();

                if ($fresh !== null) {
                    $claimed[] = $fresh;
                }
            }

            return $claimed;
        });

        foreach ($events as $index => $event) {
            $this->processClaimedEvent($event, $index);
        }
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
            // Skip Pending rows whose aggregate already has a Processing sibling.
            // That marks an in-flight scoped processAggregate drain (claim-all);
            // cron must not interleave those leftovers until the drain finishes
            // or stale recovery returns Processing → Pending.
            $event = OutboxEvent::query()
                ->where('status', OutboxEventStatus::Pending)
                ->where('available_at', '<=', now())
                ->whereNotExists(function ($query): void {
                    $query->selectRaw('1')
                        ->from('outbox_events as aggregate_siblings')
                        ->whereColumn('aggregate_siblings.aggregate_type', 'outbox_events.aggregate_type')
                        ->whereColumn('aggregate_siblings.aggregate_id', 'outbox_events.aggregate_id')
                        ->where('aggregate_siblings.status', OutboxEventStatus::Processing->value);
                })
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

    private function processClaimedEvent(OutboxEvent $event, int $processedBeforeInBatch): void
    {
        try {
            $this->dispatch($event, $processedBeforeInBatch);

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

    private function dispatch(OutboxEvent $event, int $processedBeforeInBatch = 0): void
    {
        match ($event->event_type) {
            CashfreeWebhookOutboxWriter::EVENT_TYPE => $this->dispatchCashfreeDeferredOperation($event),
            InteraktWebhookOutboxWriter::EVENT_TYPE => $this->dispatchInteraktWebhookProcessing($event),
            InteraktFlowWebhookOutboxWriter::EVENT_TYPE => $this->dispatchInteraktFlowWebhookProcessing($event),
            InteraktOutboundOutboxWriter::EVENT_TYPE => $this->dispatchInteraktTemplateSend($event),
            BonvoiceWebhookOutboxWriter::EVENT_TYPE => $this->dispatchBonvoiceWebhookProcessing($event, $processedBeforeInBatch),
            IncomingEmailOutboxWriter::EVENT_TYPE => $this->dispatchIncomingEmailProcessing($event),
            EInvoiceOutboxWriter::EVENT_TYPE => $this->einvoiceProcessor->process($event),
            default => throw new RuntimeException('Unknown outbox event type: '.$event->event_type),
        };
    }

    private function dispatchIncomingEmailProcessing(OutboxEvent $event): void
    {
        $payload = $event->payload ?? [];
        $messageId = (int) ($payload['incoming_email_message_id'] ?? 0);

        if ($messageId <= 0) {
            throw new RuntimeException('Incoming email outbox event is missing incoming_email_message_id.');
        }

        $message = IncomingEmailMessage::query()->find($messageId);

        if ($message === null) {
            throw new RuntimeException('Incoming email message not found: '.$messageId);
        }

        $this->incomingEmailProcessorService->process($message);
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

    private function dispatchInteraktFlowWebhookProcessing(OutboxEvent $event): void
    {
        $payload = $event->payload ?? [];
        $webhookLogId = (int) ($payload['webhook_log_id'] ?? 0);

        if ($webhookLogId <= 0) {
            throw new RuntimeException('Interakt flow outbox event is missing webhook_log_id.');
        }

        $webhookLog = InteraktWebhookLog::query()->find($webhookLogId);

        if ($webhookLog === null) {
            throw new RuntimeException('Interakt webhook log not found: '.$webhookLogId);
        }

        $this->interaktFlowWebhookProcessorService->process($webhookLog);
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

    private function dispatchBonvoiceWebhookProcessing(OutboxEvent $event, int $processedBeforeInBatch = 0): void
    {
        $payload = $event->payload ?? [];
        $webhookLogId = (int) ($payload['webhook_log_id'] ?? 0);

        if ($webhookLogId <= 0) {
            throw new RuntimeException('BonVoice outbox event is missing webhook_log_id.');
        }

        $webhookLog = BonvoiceWebhookLog::query()->find($webhookLogId);

        if ($webhookLog === null) {
            throw new RuntimeException('BonVoice webhook log not found: '.$webhookLogId);
        }

        if (! $this->incomingCallLatency->enabled()) {
            $this->bonvoiceWebhookProcessorService->process($webhookLog);

            return;
        }

        if (! $this->incomingCallLatency->hasBegun()
            || $this->incomingCallLatency->webhookLogId() !== $webhookLog->id) {
            $this->incomingCallLatency->beginFromWebhookLog($webhookLog);
        }

        $outboxAheadCount = OutboxEvent::query()
            ->where('id', '<', $event->id)
            ->where('status', '!=', OutboxEventStatus::Completed)
            ->count();

        $this->incomingCallLatency->mark(BonvoiceIncomingCallLatency::STAGE_OUTBOX, null, [
            'outbox_event_id' => $event->id,
            'outbox_ahead_count' => $outboxAheadCount,
            'outbox_processed_before' => $processedBeforeInBatch,
            'outbox_event_type' => $event->event_type,
        ]);

        $this->bonvoiceWebhookProcessorService->process($webhookLog);
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
