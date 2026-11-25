<?php

namespace App\Services\Cashfree;

use App\Models\CashfreeWebhookLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class CashfreeWebhookForensicLogger
{
    public function __construct(
        private readonly CashfreeWebhookPayloadParser $payloadParser,
    ) {}

    public function logHandled(
        Request $request,
        CashfreeWebhookLog $webhookLog,
        string $outcome,
        float $durationMs,
    ): void {
        $payload = is_array($webhookLog->request_payload) ? $webhookLog->request_payload : [];

        Log::info('[Cashfree Webhook] Received', [
            'timestamp' => now()->toIso8601String(),
            'correlation_id' => (string) $webhookLog->id,
            'webhook_log_id' => $webhookLog->id,
            'event_type' => $this->payloadParser->eventType($payload),
            'cf_payment_id' => $webhookLog->cf_payment_id ?? $this->payloadParser->cfPaymentId($payload),
            'cashfree_order_id' => $this->payloadParser->orderId($payload),
            'remote_ip' => $request->ip(),
            'http_method' => $request->method(),
            'processing_outcome' => $outcome,
            'processing_status' => $webhookLog->processing_status,
            'duration_ms' => round($durationMs, 2),
        ]);
    }
}
