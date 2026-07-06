<?php

namespace App\Services\Interakt;

use App\Enums\SupportAppointmentBookingSource;
use App\Models\InteraktWebhookLog;
use App\Services\Interakt\Exceptions\InteraktFlowWebhookProcessingException;
use App\Services\Interakt\Exceptions\WhatsAppFlowTokenException;
use App\Services\SupportAppointmentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InteraktFlowWebhookProcessorService
{
    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_IGNORED = 'ignored';

    public function __construct(
        private readonly InteraktFlowWebhookPayloadParser $payloadParser,
        private readonly WhatsAppFlowService $flowService,
        private readonly SupportAppointmentService $appointmentService,
    ) {}

    public function process(InteraktWebhookLog $webhookLog): InteraktWebhookLog
    {
        $payload = $webhookLog->payload ?? [];

        if (! $this->payloadParser->isFlowResponse($payload)) {
            return $this->markIgnored($webhookLog, 'Unsupported Interakt flow webhook event type.');
        }

        try {
            DB::transaction(function () use ($webhookLog, $payload): void {
                $lockedWebhookLog = InteraktWebhookLog::query()
                    ->whereKey($webhookLog->id)
                    ->lockForUpdate()
                    ->first();

                if ($lockedWebhookLog === null) {
                    throw new InteraktFlowWebhookProcessingException('Interakt flow webhook log not found.');
                }

                if ($lockedWebhookLog->processing_status === self::STATUS_PROCESSED) {
                    return;
                }

                $responseJson = $this->payloadParser->responseJson($payload);

                if ($responseJson === null) {
                    throw new InteraktFlowWebhookProcessingException('Malformed flow response_json payload.');
                }

                $flowToken = $this->payloadParser->flowToken($responseJson);

                if ($flowToken === null) {
                    throw new InteraktFlowWebhookProcessingException('Missing or invalid flow_token in flow response.');
                }

                $incident = $this->flowService->resolveIncident($flowToken);

                $this->appointmentService->book(
                    incident: $incident,
                    data: $this->payloadParser->bookingData($responseJson),
                    bookingSource: SupportAppointmentBookingSource::WhatsAppFlow,
                );

                $lockedWebhookLog->update([
                    'processing_status' => self::STATUS_PROCESSED,
                    'processing_error' => null,
                    'processed_at' => now(),
                ]);
            });

            return $webhookLog->fresh();
        } catch (InteraktFlowWebhookProcessingException|ValidationException|WhatsAppFlowTokenException $exception) {
            $this->markFailed($webhookLog, $exception->getMessage());

            throw $exception;
        } catch (\Throwable $exception) {
            $this->markFailed($webhookLog, $exception->getMessage());

            throw $exception;
        }
    }

    private function markIgnored(InteraktWebhookLog $webhookLog, string $reason): InteraktWebhookLog
    {
        $webhookLog->update([
            'processing_status' => self::STATUS_IGNORED,
            'processing_error' => $reason,
            'processed_at' => now(),
        ]);

        return $webhookLog->fresh();
    }

    private function markFailed(InteraktWebhookLog $webhookLog, string $message): void
    {
        $webhookLog->update([
            'processing_status' => self::STATUS_FAILED,
            'processing_error' => $message,
            'processed_at' => now(),
        ]);
    }
}
