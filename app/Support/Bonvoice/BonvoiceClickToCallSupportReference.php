<?php

namespace App\Support\Bonvoice;

use App\Enums\BonvoiceClickToCallFailureCode;
use Illuminate\Support\Facades\Log;

/**
 * Support-friendly reference for Click-to-Call failures.
 *
 * Diagnosis chain (internal only — never expose provider bodies to the client):
 *
 *   reference_id (BV-XXXXXXXX)
 *     → structured "[BonVoice Click-to-Call] Failure" log entry
 *     → failure_code (BonvoiceClickToCallFailureCode)
 *     → provider_response_code / provider_response_description (logs only)
 *
 * The reference prefix is derived from the Bonvoice event_id or correlation id
 * when available so support can correlate UI reports with a single log line.
 */
final class BonvoiceClickToCallSupportReference
{
    public static function format(?string $eventId = null, ?string $correlationId = null): string
    {
        $source = $eventId ?? $correlationId ?? strtoupper(bin2hex(random_bytes(8)));

        return 'BV-'.substr($source, 0, 8);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function logFailure(
        string $referenceId,
        BonvoiceClickToCallFailureCode $failureCode,
        array $context,
        bool $retriable,
        ?float $executionTimeMs = null,
        ?int $providerResponseCode = null,
        ?string $providerResponseDescription = null,
    ): void {
        Log::warning('[BonVoice Click-to-Call] Failure', [
            'reference_id' => $referenceId,
            'failure_code' => $failureCode->value,
            'event_id' => $context['event_id'] ?? null,
            'incident_id' => $context['incident_id'] ?? null,
            'order_id' => $context['order_id'] ?? null,
            'user_id' => $context['user_id'] ?? null,
            'correlation_id' => $context['correlation_id'] ?? $context['event_id'] ?? null,
            'execution_time_ms' => $executionTimeMs,
            'provider_response_code' => $providerResponseCode,
            'provider_response_description' => $providerResponseDescription,
            'retriable' => $retriable,
        ]);
    }
}
