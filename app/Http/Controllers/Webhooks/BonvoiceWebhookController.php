<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\BonvoiceWebhookLog;
use App\Services\Bonvoice\BonvoiceWebhookAuthVerifier;
use App\Services\Bonvoice\BonvoiceWebhookOutboxWriter;
use App\Services\Bonvoice\BonvoiceWebhookPayloadParser;
use App\Services\Outbox\OutboxProcessorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class BonvoiceWebhookController extends Controller
{
    public function __construct(
        private readonly BonvoiceWebhookPayloadParser $payloadParser,
        private readonly BonvoiceWebhookOutboxWriter $outboxWriter,
        private readonly BonvoiceWebhookAuthVerifier $authVerifier,
        private readonly OutboxProcessorService $outboxProcessorService,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        try {
            $this->logWebhook($request);
            $webhookLog = $this->storeWebhook($request);
            $payload = $webhookLog->payload ?? [];

            if ($this->authVerifier->shouldVerify()) {
                $authResult = $this->authVerifier->verify($request, $payload);

                if (! $authResult->isValid()) {
                    $this->markAuthVerificationFailed($webhookLog, $authResult->error());

                    return response()->json([
                        'status' => 'error',
                        'message' => $authResult->error(),
                    ], $authResult->statusCode());
                }
            }

            $this->outboxWriter->writeProcessingJob($webhookLog->id);
            $this->outboxProcessorService->process();

            return response()->json(['status' => 'ok'], 200);
        } catch (Throwable $exception) {
            Log::error('[BonVoice Webhook] Processing failed', [
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
        Log::info('[BonVoice Webhook] Received', [
            'timestamp' => now()->toIso8601String(),
            'remote_ip' => $request->ip(),
            'http_method' => $request->method(),
            'user_agent' => $request->userAgent(),
            'request_headers' => $request->headers->all(),
            'raw_json_body' => $request->getContent(),
            'parsed_payload' => $request->all(),
        ]);
    }

    private function markAuthVerificationFailed(BonvoiceWebhookLog $webhookLog, ?string $error): void
    {
        $webhookLog->update([
            'processing_status' => BonvoiceWebhookLog::STATUS_FAILED,
            'processing_error' => $error,
            'processed_at' => now(),
        ]);
    }

    private function storeWebhook(Request $request): BonvoiceWebhookLog
    {
        $payload = $this->resolvePayload($request);

        return BonvoiceWebhookLog::query()->create([
            'event_type' => $this->payloadParser->eventType($payload),
            'payload' => $payload,
            'raw_body' => $request->getContent(),
            'request_headers' => $request->headers->all(),
            'received_at' => now(),
            'processing_status' => BonvoiceWebhookLog::STATUS_RECEIVED,
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
