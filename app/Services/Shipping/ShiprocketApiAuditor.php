<?php

namespace App\Services\Shipping;

use App\Models\ShipmentEvent;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ShiprocketApiAuditor
{
    /**
     * @param  array{method: string, path: string, status: int, json: array<string, mixed>}  $exchange
     */
    public function recordOperation(
        string $operation,
        array $exchange,
        float $startedAt,
        ?string $domainStatus = null,
        ?string $providerMessage = null,
        ?bool $retryable = null,
    ): void {
        try {
            $context = ShiprocketApiAuditScope::current();
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $httpStatus = (int) ($exchange['status'] ?? 0);
            $json = is_array($exchange['json'] ?? null) ? $exchange['json'] : [];
            $summary = ShiprocketApiResponseSanitizer::summarizeForOperation($operation, $json, $httpStatus);
            $outcome = ShiprocketApiResponseSanitizer::inferOutcome($operation, $httpStatus, $summary, $domainStatus);

            if ($providerMessage === null && $httpStatus >= 400) {
                $providerMessage = self::messageFromJson($json, $httpStatus);
            }

            $payload = ShiprocketApiResponseSanitizer::auditPayload(
                operation: $operation,
                httpMethod: (string) ($exchange['method'] ?? 'GET'),
                endpoint: (string) ($exchange['path'] ?? ''),
                httpStatus: $httpStatus,
                outcome: $outcome,
                context: $context,
                durationMs: $durationMs,
                summaryFields: $summary,
                providerMessage: $providerMessage,
                retryable: $retryable,
            );

            if ($context?->shipmentId !== null) {
                $shipmentId = (int) $context->shipmentId;
                ShipmentEvent::query()->create([
                    'shipment_id' => $shipmentId,
                    'source' => 'shiprocket_api',
                    'activity' => 'shiprocket_api:'.$operation,
                    'awb' => $context->awb,
                    'external_order_id' => $context->externalOrderId,
                    'external_shipment_id' => $context->externalShipmentId,
                    'payload' => $payload,
                ]);
            } else {
                Log::channel('daily')->info('shiprocket_api_audit', $payload);
            }
        } catch (Throwable $exception) {
            Log::channel('daily')->warning('shiprocket_api_audit_failed', [
                'operation' => $operation,
                'error' => ShiprocketApiResponseSanitizer::sanitizeMessage($exception->getMessage()),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private static function messageFromJson(array $json, int $httpStatus): string
    {
        foreach (['message', 'error', 'msg', 'response'] as $key) {
            $value = $json[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return ShiprocketApiResponseSanitizer::sanitizeMessage((string) $value);
            }
        }

        return 'HTTP '.$httpStatus;
    }
}
