<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\InteraktWebhookLog;
use App\Models\OutboxEvent;
use App\Enums\OutboxEventStatus;
use App\Services\Interakt\Exceptions\InteraktFlowWebhookProcessingException;
use App\Services\Interakt\Exceptions\WhatsAppFlowTokenException;
use App\Services\Interakt\InteraktFlowWebhookOutboxWriter;
use App\Services\Interakt\InteraktFlowWebhookPayloadParser;
use App\Services\Interakt\InteraktFlowWebhookProcessorService;
use App\Services\Interakt\InteraktWebhookSignatureVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class InteraktFlowWebhookController extends Controller
{
    public function __construct(
        private readonly InteraktFlowWebhookPayloadParser $payloadParser,
        private readonly InteraktFlowWebhookOutboxWriter $outboxWriter,
        private readonly InteraktFlowWebhookProcessorService $flowWebhookProcessorService,
        private readonly InteraktWebhookSignatureVerifier $signatureVerifier,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $webhookLog = null;

        try {
            $this->logWebhook($request);
            $webhookLog = $this->storeWebhook($request);

            if (config('interakt.verify_signature')) {
                if (! $this->signatureVerifier->hasRequiredHeaders($request)) {
                    $this->markSignatureVerificationFailed($webhookLog);

                    return response()->json(['status' => 'error'], 400);
                }

                if (! $this->signatureVerifier->verify($request)) {
                    $this->markSignatureVerificationFailed($webhookLog);

                    return response()->json(['status' => 'error'], 401);
                }
            }

            if (! $this->payloadParser->isFlowResponse($webhookLog->payload ?? [])) {
                $webhookLog->update([
                    'processing_status' => InteraktFlowWebhookProcessorService::STATUS_IGNORED,
                    'processing_error' => 'ignored_unsupported_event_type',
                    'processed_at' => now(),
                ]);

                return response()->json(['status' => 'ok'], 200);
            }

            $this->outboxWriter->writeProcessingJob($webhookLog->id);
            $this->flowWebhookProcessorService->process($webhookLog);
            $this->markOutboxCompleted($webhookLog->id);

            return response()->json(['status' => 'ok'], 200);
        } catch (InteraktFlowWebhookProcessingException|ValidationException|WhatsAppFlowTokenException $exception) {
            if ($webhookLog instanceof InteraktWebhookLog) {
                $this->markOutboxFailed($webhookLog->id, $exception->getMessage());
            }

            Log::warning('[Interakt Flow Webhook] Payload rejected', [
                'timestamp' => now()->toIso8601String(),
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            return response()->json(['status' => 'error', 'message' => $exception->getMessage()], 422);
        } catch (Throwable $exception) {
            if ($webhookLog instanceof InteraktWebhookLog) {
                $this->markOutboxFailed($webhookLog->id, $exception->getMessage());
            }

            Log::error('[Interakt Flow Webhook] Processing failed', [
                'timestamp' => now()->toIso8601String(),
                'remote_ip' => $request->ip(),
                'http_method' => $request->method(),
                'user_agent' => $request->userAgent(),
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            return response()->json(['status' => 'error'], 500);
        }
    }

    private function logWebhook(Request $request): void
    {
        Log::info('[Interakt Flow Webhook] Received', [
            'timestamp' => now()->toIso8601String(),
            'remote_ip' => $request->ip(),
            'http_method' => $request->method(),
            'user_agent' => $request->userAgent(),
            'request_headers' => $request->headers->all(),
            'raw_json_body' => $request->getContent(),
            'parsed_payload' => $request->all(),
        ]);
    }

    private function markSignatureVerificationFailed(InteraktWebhookLog $webhookLog): void
    {
        $webhookLog->update([
            'processing_status' => InteraktWebhookLog::STATUS_FAILED,
            'processing_error' => InteraktWebhookSignatureVerifier::ERROR_INVALID_SIGNATURE,
            'processed_at' => now(),
        ]);
    }

    private function storeWebhook(Request $request): InteraktWebhookLog
    {
        $payload = $this->resolvePayload($request);

        return InteraktWebhookLog::query()->create([
            'event_type' => $this->payloadParser->eventType($payload),
            'payload' => $payload,
            'raw_body' => $request->getContent(),
            'request_headers' => $request->headers->all(),
            'received_at' => now(),
            'processing_status' => InteraktWebhookLog::STATUS_RECEIVED,
        ]);
    }

    private function markOutboxCompleted(int $webhookLogId): void
    {
        OutboxEvent::query()
            ->where('idempotency_key', sprintf('interakt.flow.webhook.process.%d', $webhookLogId))
            ->update([
                'status' => OutboxEventStatus::Completed,
                'processed_at' => now(),
                'last_error' => null,
            ]);
    }

    private function markOutboxFailed(int $webhookLogId, string $message): void
    {
        OutboxEvent::query()
            ->where('idempotency_key', sprintf('interakt.flow.webhook.process.%d', $webhookLogId))
            ->update([
                'status' => OutboxEventStatus::Failed,
                'last_error' => $message,
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolvePayload(Request $request): array
    {
        $payload = $request->all();

        if ($payload !== []) {
            return $payload;
        }

        $rawBody = $request->getContent();

        if ($rawBody === '') {
            return [];
        }

        $decoded = json_decode($rawBody, true);

        return is_array($decoded) ? $decoded : [];
    }
}
