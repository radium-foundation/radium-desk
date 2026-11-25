<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\CashfreeWebhookLog;
use App\Services\Cashfree\CashfreeWebhookForensicLogger;
use App\Services\Cashfree\CashfreeWebhookPayloadParser;
use App\Services\Cashfree\CashfreeWebhookProcessorService;
use App\Services\Cashfree\CashfreeWebhookSignatureVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class CashfreeWebhookController extends Controller
{
    public function __construct(
        private readonly CashfreeWebhookPayloadParser $payloadParser,
        private readonly CashfreeWebhookProcessorService $webhookProcessorService,
        private readonly CashfreeWebhookSignatureVerifier $signatureVerifier,
        private readonly CashfreeWebhookForensicLogger $forensicLogger,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $startedAt = hrtime(true);

        try {
            $webhookLog = $this->storeWebhook($request);

            if (config('cashfree.verify_signature')) {
                if (! $this->signatureVerifier->hasRequiredHeaders($request)) {
                    $this->markSignatureVerificationFailed($webhookLog);
                    $this->logForensicOutcome($request, $webhookLog->fresh(), 'signature_headers_missing', $startedAt);

                    return response()->json(['status' => 'error'], 400);
                }

                if (! $this->signatureVerifier->verify($request)) {
                    $this->markSignatureVerificationFailed($webhookLog);
                    $this->logForensicOutcome($request, $webhookLog->fresh(), 'signature_invalid', $startedAt);

                    return response()->json(['status' => 'error'], 401);
                }
            }

            $processedLog = $this->webhookProcessorService->process($webhookLog);
            $this->logForensicOutcome($request, $processedLog, 'ok', $startedAt);

            return response()->json(['status' => 'ok'], 200);
        } catch (Throwable $exception) {
            Log::error('[Cashfree Webhook] Processing failed', [
                'timestamp' => now()->toIso8601String(),
                'remote_ip' => $request->ip(),
                'http_method' => $request->method(),
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            return response()->json(['status' => 'error'], 500);
        }
    }

    private function logForensicOutcome(
        Request $request,
        CashfreeWebhookLog $webhookLog,
        string $outcome,
        int $startedAt,
    ): void {
        $durationMs = (hrtime(true) - $startedAt) / 1e6;
        $this->forensicLogger->logHandled($request, $webhookLog, $outcome, $durationMs);
    }

    private function markSignatureVerificationFailed(CashfreeWebhookLog $webhookLog): void
    {
        $webhookLog->update([
            'processing_status' => CashfreeWebhookLog::STATUS_FAILED,
            'processing_error' => CashfreeWebhookSignatureVerifier::ERROR_INVALID_SIGNATURE,
            'processed_at' => now(),
        ]);
    }

    private function storeWebhook(Request $request): CashfreeWebhookLog
    {
        $payload = $this->resolvePayload($request);

        return CashfreeWebhookLog::query()->create([
            'webhook_version' => $this->resolveWebhookVersion($request),
            'cf_payment_id' => $this->payloadParser->cfPaymentId($payload),
            'request_headers' => $request->headers->all(),
            'request_payload' => $payload,
            'received_at' => now(),
            'source_ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'processing_status' => CashfreeWebhookLog::STATUS_RECEIVED,
        ]);
    }

    private function resolveWebhookVersion(Request $request): ?string
    {
        foreach (['x-webhook-version', 'x-cashfree-webhook-version', 'webhook-version'] as $header) {
            $value = $request->header($header);

            if ($value !== null && $value !== '') {
                return (string) $value;
            }
        }

        $payload = $this->resolvePayload($request);

        foreach (['webhook_version', 'version'] as $key) {
            if (isset($payload[$key]) && $payload[$key] !== '') {
                return (string) $payload[$key];
            }
        }

        return null;
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
