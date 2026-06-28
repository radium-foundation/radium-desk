<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\CashfreeWebhookLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class CashfreeWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        try {
            $this->logWebhook($request);
            $this->storeWebhook($request);

            return response()->json(['status' => 'ok'], 200);
        } catch (Throwable $exception) {
            Log::error('[Cashfree Webhook] Processing failed', [
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
        Log::info('[Cashfree Webhook] Received', [
            'timestamp' => now()->toIso8601String(),
            'remote_ip' => $request->ip(),
            'http_method' => $request->method(),
            'user_agent' => $request->userAgent(),
            'request_headers' => $request->headers->all(),
            'raw_json_body' => $request->getContent(),
            'parsed_payload' => $request->all(),
        ]);
    }

    private function storeWebhook(Request $request): void
    {
        CashfreeWebhookLog::query()->create([
            'webhook_version' => $this->resolveWebhookVersion($request),
            'request_headers' => $request->headers->all(),
            'request_payload' => $this->resolvePayload($request),
            'raw_body' => $request->getContent(),
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
