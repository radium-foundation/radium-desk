<?php

namespace App\Services\Shipping;

final class ShiprocketApiResponseSanitizer
{
    public const MAX_MESSAGE_CHARS = 480;

    public const MAX_JSON_BYTES = 4096;

    /**
     * Build an allowlisted audit payload. No raw provider JSON is stored.
     *
     * @param  array<string, mixed>  $summaryFields
     * @return array<string, mixed>
     */
    public static function auditPayload(
        string $operation,
        string $httpMethod,
        string $endpoint,
        int $httpStatus,
        string $outcome,
        ?ShiprocketApiAuditContext $context,
        int $durationMs,
        array $summaryFields = [],
        ?string $providerMessage = null,
        ?bool $retryable = null,
    ): array {
        $payload = [
            'schema' => 'shiprocket_api_audit_v1',
            'operation' => $operation,
            'http_method' => strtoupper($httpMethod),
            'endpoint' => self::normalizeEndpoint($endpoint),
            'http_status' => $httpStatus,
            'outcome' => $outcome,
            'duration_ms' => $durationMs,
        ];

        if ($context !== null) {
            if ($context->correlationId !== null && $context->correlationId !== '') {
                $payload['correlation_id'] = $context->correlationId;
            }
            if ($context->merchantReference !== null && $context->merchantReference !== '') {
                $payload['merchant_reference'] = $context->merchantReference;
            }
            if ($context->hardwareFulfilmentId !== null) {
                $payload['hardware_fulfilment_id'] = $context->hardwareFulfilmentId;
            }
            if ($context->externalOrderId !== null && $context->externalOrderId !== '') {
                $payload['external_order_id'] = $context->externalOrderId;
            }
            if ($context->externalShipmentId !== null && $context->externalShipmentId !== '') {
                $payload['external_shipment_id'] = $context->externalShipmentId;
            }
            if ($context->awb !== null && $context->awb !== '') {
                $payload['awb'] = $context->awb;
            }
        }

        foreach ($summaryFields as $key => $value) {
            if (! is_string($key) || $key === '') {
                continue;
            }
            if ($value === null || $value === '') {
                continue;
            }
            if (is_scalar($value)) {
                $payload[$key] = self::sanitizeScalar((string) $value);
            } elseif (is_bool($value)) {
                $payload[$key] = $value;
            } elseif (is_int($value) || is_float($value)) {
                $payload[$key] = $value;
            }
        }

        if ($providerMessage !== null && trim($providerMessage) !== '') {
            $payload['provider_message'] = self::sanitizeMessage($providerMessage);
        }

        if ($retryable !== null) {
            $payload['retryable'] = $retryable;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<string, scalar|bool|null>
     */
    public static function summarizeForOperation(string $operation, array $json, int $httpStatus): array
    {
        return match ($operation) {
            'create_order' => self::summarizeCreateOrder($json),
            'assign_awb', 'reassign_courier_awb' => self::summarizeAssignAwb($json),
            'search_orders' => self::summarizeSearch($json),
            'generate_label' => self::summarizeLabel($json),
            'request_pickup' => self::summarizePickup($json),
            'generate_manifest' => self::summarizeManifest($json),
            'track_awb', 'track_shipment' => self::summarizeTrack($json),
            'list_courier_options' => self::summarizeCourierOptions($json),
            default => self::summarizeGeneric($json, $httpStatus),
        };
    }

    public static function inferOutcome(string $operation, int $httpStatus, array $summary, ?string $domainStatus): string
    {
        if ($httpStatus === 429 || $httpStatus >= 500) {
            return 'failed';
        }

        if ($httpStatus >= 400) {
            return 'rejected';
        }

        if ($operation === 'generate_label') {
            $labelCreated = $summary['label_created'] ?? null;
            if ($labelCreated === 0 || $labelCreated === false || $labelCreated === '0') {
                return 'partial';
            }
        }

        if ($domainStatus !== null) {
            $normalized = strtolower(trim($domainStatus));

            return match ($normalized) {
                'failed' => 'failed',
                'rejected' => 'rejected',
                'partial' => 'partial',
                'unknown' => $summary['provider_track_error'] ?? false ? 'rejected' : 'success',
                default => in_array($normalized, ['created', 'assigned', 'generated', 'requested', 'already_requested', 'cancelled', 'listed', 'available'], true)
                    ? 'success'
                    : 'success',
            };
        }

        return 'success';
    }

    public static function sanitizeMessage(string $message): string
    {
        $message = self::stripSecrets($message);
        $message = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $message) ?? $message;

        if (strlen($message) > self::MAX_MESSAGE_CHARS) {
            return substr($message, 0, self::MAX_MESSAGE_CHARS).'…';
        }

        return $message;
    }

    private static function normalizeEndpoint(string $path): string
    {
        $path = '/'.ltrim($path, '/');

        return preg_replace('/\/\d+/', '/{id}', $path) ?? $path;
    }

    private static function sanitizeScalar(string $value): string
    {
        return self::sanitizeMessage($value);
    }

    private static function stripSecrets(string $text): string
    {
        $text = preg_replace('/bearer\s+[A-Za-z0-9._\-]+/i', '[redacted]', $text) ?? $text;
        $text = preg_replace('/\b[A-Za-z0-9_-]{20,}\.[A-Za-z0-9._-]{10,}\b/', '[redacted]', $text) ?? $text;

        return trim($text);
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<string, scalar|bool|null>
     */
    private static function summarizeCreateOrder(array $json): array
    {
        return array_filter([
            'provider_order_id' => self::scalar($json['order_id'] ?? null),
            'provider_shipment_id' => self::scalar($json['shipment_id'] ?? null),
            'awb_code' => self::scalar($json['awb_code'] ?? $json['awb'] ?? null),
            'provider_status_code' => self::scalar($json['status_code'] ?? null),
        ], static fn ($v) => $v !== null && $v !== '');
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<string, scalar|bool|null>
     */
    private static function summarizeAssignAwb(array $json): array
    {
        $data = is_array($json['response']['data'] ?? null)
            ? $json['response']['data']
            : (is_array($json['data'] ?? null) ? $json['data'] : $json);

        return array_filter([
            'awb_code' => self::scalar($data['awb_code'] ?? $data['awb'] ?? null),
            'courier_company_id' => self::scalar($data['courier_company_id'] ?? $data['courier_id'] ?? null),
            'provider_status_code' => self::scalar($json['status_code'] ?? null),
        ], static fn ($v) => $v !== null && $v !== '');
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<string, scalar|bool|null>
     */
    private static function summarizeSearch(array $json): array
    {
        $rows = $json['data'] ?? $json['orders'] ?? null;
        $first = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : [];

        return array_filter([
            'result_count' => is_array($rows) ? count($rows) : 0,
            'provider_order_id' => self::scalar($first['id'] ?? $first['order_id'] ?? null),
            'channel_order_id' => self::scalar($first['channel_order_id'] ?? null),
            'provider_status' => self::scalar($first['status'] ?? null),
        ], static fn ($v) => $v !== null && $v !== '');
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<string, scalar|bool|null>
     */
    private static function summarizeLabel(array $json): array
    {
        return array_filter([
            'label_created' => $json['label_created'] ?? null,
            'has_label_url' => filled($json['label_url'] ?? null),
            'not_created_count' => is_array($json['not_created'] ?? null) ? count($json['not_created']) : null,
        ], static fn ($v) => $v !== null && $v !== '');
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<string, scalar|bool|null>
     */
    private static function summarizePickup(array $json): array
    {
        return array_filter([
            'pickup_status' => self::scalar($json['pickup_status'] ?? $json['status'] ?? null),
            'provider_status_code' => self::scalar($json['status_code'] ?? null),
        ], static fn ($v) => $v !== null && $v !== '');
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<string, scalar|bool|null>
     */
    private static function summarizeManifest(array $json): array
    {
        return array_filter([
            'manifest_id' => self::scalar($json['manifest_id'] ?? $json['payload']['manifest_id'] ?? null),
            'has_manifest_url' => filled($json['manifest_url'] ?? $json['payload']['manifest_url'] ?? null),
        ], static fn ($v) => $v !== null && $v !== '');
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<string, scalar|bool|null>
     */
    private static function summarizeTrack(array $json): array
    {
        $tracking = $json['tracking_data'] ?? null;
        if (! is_array($tracking)) {
            foreach ($json as $value) {
                if (is_array($value) && isset($value['tracking_data']) && is_array($value['tracking_data'])) {
                    $tracking = $value['tracking_data'];
                    break;
                }
            }
        }
        $tracking = is_array($tracking) ? $tracking : [];
        $first = is_array($tracking['shipment_track'][0] ?? null) ? $tracking['shipment_track'][0] : [];

        return array_filter([
            'shipment_status' => self::scalar($tracking['shipment_status'] ?? $tracking['status'] ?? null),
            'current_status_id' => self::scalar($first['current_status_id'] ?? null),
            'current_status' => self::scalar($first['current_status'] ?? null),
            'provider_track_error' => filled($tracking['error'] ?? null),
        ], static fn ($v) => $v !== null && $v !== '' && $v !== false);
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<string, scalar|bool|null>
     */
    private static function summarizeCourierOptions(array $json): array
    {
        $data = is_array($json['data'] ?? null) ? $json['data'] : $json;
        $companies = $data['available_courier_companies'] ?? null;

        return array_filter([
            'courier_option_count' => is_array($companies) ? count($companies) : 0,
        ], static fn ($v) => $v !== null && $v !== '');
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<string, scalar|bool|null>
     */
    private static function summarizeGeneric(array $json, int $httpStatus): array
    {
        return array_filter([
            'provider_status_code' => self::scalar($json['status_code'] ?? null),
            'http_status' => $httpStatus,
        ], static fn ($v) => $v !== null && $v !== '');
    }

    private static function scalar(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : self::sanitizeScalar($text);
    }
}
