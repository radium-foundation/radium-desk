<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\InteraktWebhookLog;
use App\Services\Interakt\InteraktWebhookOutboxWriter;
use App\Services\Interakt\InteraktWebhookPayloadParser;
use App\Services\Interakt\InteraktWebhookSignatureVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class InteraktWebhookController extends Controller
{
    public function __construct(
        private readonly InteraktWebhookPayloadParser $payloadParser,
        private readonly InteraktWebhookOutboxWriter $outboxWriter,
        private readonly InteraktWebhookSignatureVerifier $signatureVerifier,
    ) {}

    public function handle(Request $request): JsonResponse
    {
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

            // Enqueue only — never drain the global outbox (or unrelated aggregates)
            // inside the webhook HTTP request. Cron `outbox:process` continues work.
            $this->outboxWriter->writeProcessingJob($webhookLog->id);

            return response()->json(['status' => 'ok'], 200);
        } catch (Throwable $exception) {
            Log::error('[Interakt Webhook] Processing failed', [
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
        Log::info('[Interakt Webhook] Received', [
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
            'request_headers' => $request->headers->all(),
            'received_at' => now(),
            'processing_status' => InteraktWebhookLog::STATUS_RECEIVED,
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
