<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
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
}
