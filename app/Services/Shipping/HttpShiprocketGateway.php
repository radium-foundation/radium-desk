<?php

namespace App\Services\Shipping;

use App\Contracts\Shipping\ShiprocketGateway;
use App\Services\Shipping\Data\ShiprocketAwbResult;
use App\Services\Shipping\Data\ShiprocketCancelResult;
use App\Services\Shipping\Data\ShiprocketCourierOption;
use App\Services\Shipping\Data\ShiprocketCourierOptionsRequest;
use App\Services\Shipping\Data\ShiprocketCourierOptionsResult;
use App\Services\Shipping\Data\ShiprocketCreateOrderRequest;
use App\Services\Shipping\Data\ShiprocketCreateOrderResult;
use App\Services\Shipping\Data\ShiprocketDocumentResult;
use App\Services\Shipping\Data\ShiprocketPickupResult;
use App\Services\Shipping\Data\ShiprocketSearchResult;
use App\Services\Shipping\Data\ShiprocketTokenResult;
use App\Services\Shipping\Data\ShiprocketTrackResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Production-capable Shiprocket HTTP adapter. Not bound unless shipping is
 * enabled, provider is shiprocket, http_enabled is true, and credentials exist.
 * Isolated one-order fulfilment may instantiate this in-process only.
 */
final class HttpShiprocketGateway implements ShiprocketGateway
{
    private ?string $token = null;

    public function provider(): string
    {
        return 'shiprocket';
    }

    public function acquireToken(): ShiprocketTokenResult
    {
        $this->assertConfigured();

        try {
            $response = $this->send('post', '/auth/login', [
                'email' => config('shipping.api_email'),
                'password' => config('shipping.api_password'),
            ], authenticated: false);
        } catch (ShiprocketNonRetryableException) {
            throw new ShiprocketNonRetryableException('Shiprocket authentication failed.');
        }

        $token = trim((string) ($response['json']['token'] ?? ''));
        if ($token === '') {
            throw new ShiprocketNonRetryableException('Shiprocket authentication failed.');
        }

        $this->token = $token;

        return new ShiprocketTokenResult(token: $token, ttlSeconds: 86400);
    }

    public function createOrder(ShiprocketCreateOrderRequest $request): ShiprocketCreateOrderResult
    {
        try {
            $response = $this->send('post', '/orders/create/adhoc', $request->toAdhocPayload());
        } catch (ShiprocketRetryableException $exception) {
            return new ShiprocketCreateOrderResult(
                provider: $this->provider(),
                status: 'failed',
                correlationId: $request->correlationId,
                error: $exception->getMessage(),
                retryable: true,
            );
        }

        $json = $response['json'];
        $orderId = $this->scalar($json['order_id'] ?? $json['payload']['order_id'] ?? null);
        $shipmentId = $this->scalar($json['shipment_id'] ?? $json['payload']['shipment_id'] ?? null);

        if ($orderId === null || $shipmentId === null) {
            return new ShiprocketCreateOrderResult(
                provider: $this->provider(),
                status: 'rejected',
                correlationId: $request->correlationId,
                error: $this->errorMessage($json, 'Shiprocket rejected shipment create.', $response['status']),
                retryable: false,
            );
        }

        return new ShiprocketCreateOrderResult(
            provider: $this->provider(),
            status: 'created',
            externalOrderId: $orderId,
            externalShipmentId: $shipmentId,
            correlationId: $request->correlationId,
            awb: $this->scalar($json['awb_code'] ?? $json['awb'] ?? null),
        );
    }

    public function searchOrders(string $search): ShiprocketSearchResult
    {
        try {
            $response = $this->send('get', '/orders', ['search' => $search]);
        } catch (ShiprocketRetryableException $exception) {
            return new ShiprocketSearchResult(
                provider: $this->provider(),
                found: false,
                merchantOrderId: $search,
                error: $exception->getMessage(),
                retryable: true,
            );
        }

        $row = $this->firstSearchRow($response['json'], $search);
        if ($row === null) {
            return new ShiprocketSearchResult(
                provider: $this->provider(),
                found: false,
                merchantOrderId: $search,
            );
        }

        $shipment = $this->firstArray($row['shipments'] ?? $row['shipment'] ?? null);

        return new ShiprocketSearchResult(
            provider: $this->provider(),
            found: true,
            externalOrderId: $this->scalar($row['id'] ?? $row['order_id'] ?? null),
            externalShipmentId: $this->scalar($shipment['id'] ?? $row['shipment_id'] ?? null),
            merchantOrderId: $this->scalar($row['channel_order_id'] ?? $row['order_id'] ?? $search) ?? $search,
            status: $this->scalar($row['status'] ?? $shipment['status'] ?? null),
            awb: $this->scalar($shipment['awb'] ?? $row['awb'] ?? $row['awb_code'] ?? null),
            courierId: $this->scalar($shipment['courier_id'] ?? $row['courier_id'] ?? null),
            courierName: $this->scalar($shipment['courier'] ?? $row['courier_name'] ?? null),
        );
    }

    public function listCourierOptions(ShiprocketCourierOptionsRequest $request): ShiprocketCourierOptionsResult
    {
        try {
            $response = $this->send('get', '/courier/serviceability/', $request->toQuery());
        } catch (ShiprocketRetryableException $exception) {
            return new ShiprocketCourierOptionsResult(
                provider: $this->provider(),
                status: 'failed',
                error: $exception->getMessage(),
                retryable: true,
            );
        } catch (ShiprocketNonRetryableException $exception) {
            return new ShiprocketCourierOptionsResult(
                provider: $this->provider(),
                status: 'rejected',
                error: $exception->getMessage(),
                retryable: false,
            );
        }

        return $this->courierOptionsFromJson($response['json']);
    }

    public function assignAwb(string $externalShipmentId, ?string $courierId = null): ShiprocketAwbResult
    {
        $body = ['shipment_id' => $externalShipmentId];
        if ($courierId !== null && trim($courierId) !== '') {
            $body['courier_id'] = $courierId;
        }

        try {
            $response = $this->send('post', '/courier/assign/awb', $body);
        } catch (ShiprocketRetryableException $exception) {
            return new ShiprocketAwbResult(
                provider: $this->provider(),
                status: 'failed',
                error: $exception->getMessage(),
                retryable: true,
            );
        }

        $data = $this->firstArray($response['json']['response']['data'] ?? $response['json']['data'] ?? $response['json']);
        $awb = $this->scalar($data['awb_code'] ?? $data['awb'] ?? $response['json']['awb_code'] ?? null);
        if ($awb === null) {
            return new ShiprocketAwbResult(
                provider: $this->provider(),
                status: 'rejected',
                error: $this->errorMessage($response['json'], 'Shiprocket rejected AWB assignment.'),
                retryable: false,
            );
        }

        return new ShiprocketAwbResult(
            provider: $this->provider(),
            status: 'assigned',
            awb: $awb,
            courierId: $this->scalar($data['courier_company_id'] ?? $data['courier_id'] ?? null),
            courierName: $this->scalar($data['courier_name'] ?? null),
        );
    }

    public function requestPickup(string $externalShipmentId): ShiprocketPickupResult
    {
        try {
            $response = $this->send('post', '/courier/generate/pickup', [
                'shipment_id' => [$externalShipmentId],
            ], true, false);
        } catch (ShiprocketRetryableException $exception) {
            return new ShiprocketPickupResult(
                provider: $this->provider(),
                status: 'failed',
                error: $exception->getMessage(),
                retryable: true,
            );
        } catch (ShiprocketNonRetryableException $exception) {
            return new ShiprocketPickupResult(
                provider: $this->provider(),
                status: 'rejected',
                error: $exception->getMessage(),
                retryable: false,
            );
        }

        if ($this->isAlreadyQueuedPickup($response['status'], $response['json'])) {
            return new ShiprocketPickupResult(
                provider: $this->provider(),
                status: 'already_requested',
                error: $this->errorMessage($response['json'], 'Already in Pickup Queue', $response['status']),
                alreadyQueued: true,
            );
        }

        if ($response['status'] >= 400) {
            return new ShiprocketPickupResult(
                provider: $this->provider(),
                status: 'rejected',
                error: $this->errorMessage($response['json'], 'Shiprocket rejected pickup generation.', $response['status']),
                retryable: false,
            );
        }

        return new ShiprocketPickupResult(
            provider: $this->provider(),
            status: 'requested',
        );
    }

    public function generateLabel(string $externalShipmentId): ShiprocketDocumentResult
    {
        try {
            $response = $this->send('post', '/courier/generate/label', [
                'shipment_id' => [$this->providerNumericId($externalShipmentId)],
            ]);
        } catch (ShiprocketRetryableException $exception) {
            return new ShiprocketDocumentResult(
                provider: $this->provider(),
                status: 'failed',
                error: $exception->getMessage(),
                retryable: true,
            );
        } catch (ShiprocketNonRetryableException $exception) {
            return new ShiprocketDocumentResult(
                provider: $this->provider(),
                status: 'rejected',
                error: $exception->getMessage(),
                retryable: false,
            );
        }

        $url = $this->scalar($response['json']['label_url'] ?? null);
        if ($url === null) {
            return new ShiprocketDocumentResult(
                provider: $this->provider(),
                status: 'rejected',
                error: $this->errorMessage($response['json'], 'Shiprocket did not return a label URL.'),
                retryable: false,
            );
        }

        return new ShiprocketDocumentResult(
            provider: $this->provider(),
            status: 'generated',
            url: $url,
        );
    }

    public function generateManifest(string $externalShipmentId): ShiprocketDocumentResult
    {
        try {
            $response = $this->send('post', '/manifests/generate', [
                'shipment_id' => [$this->providerNumericId($externalShipmentId)],
            ]);
        } catch (ShiprocketRetryableException $exception) {
            return new ShiprocketDocumentResult(
                provider: $this->provider(),
                status: 'failed',
                error: $exception->getMessage(),
                retryable: true,
            );
        } catch (ShiprocketNonRetryableException $exception) {
            return new ShiprocketDocumentResult(
                provider: $this->provider(),
                status: 'rejected',
                error: $exception->getMessage(),
                retryable: false,
            );
        }

        $json = $response['json'];
        $url = $this->scalar($json['manifest_url'] ?? $json['payload']['manifest_url'] ?? null);
        $documentId = $this->scalar($json['manifest_id'] ?? $json['payload']['manifest_id'] ?? null);

        if ($url === null && $documentId === null) {
            return new ShiprocketDocumentResult(
                provider: $this->provider(),
                status: 'rejected',
                error: $this->errorMessage($json, 'Shiprocket did not return a manifest URL or id.'),
                retryable: false,
            );
        }

        return new ShiprocketDocumentResult(
            provider: $this->provider(),
            status: 'generated',
            url: $url,
            documentId: $documentId,
        );
    }

    public function printInvoice(string $externalShipmentId): ShiprocketDocumentResult
    {
        $response = $this->send('get', '/orders/print/invoice', [
            'ids' => [$externalShipmentId],
        ]);

        return new ShiprocketDocumentResult(
            provider: $this->provider(),
            status: 'generated',
            url: $this->scalar($response['json']['invoice_url'] ?? null),
        );
    }

    public function trackByAwb(string $awb): ShiprocketTrackResult
    {
        $response = $this->send('get', '/courier/track/awb/'.$awb);
        $tracking = $this->firstArray($response['json']['tracking_data'] ?? $response['json']);

        return new ShiprocketTrackResult(
            provider: $this->provider(),
            status: $this->scalar($tracking['shipment_status'] ?? $tracking['status'] ?? 'unknown') ?? 'unknown',
            activities: is_array($tracking['shipment_track'] ?? null) ? $tracking['shipment_track'] : [],
            awb: $awb,
        );
    }

    public function trackByShipment(string $externalShipmentId): ShiprocketTrackResult
    {
        $response = $this->send('get', '/courier/track', [
            'shipment_id' => $externalShipmentId,
        ]);
        $tracking = $this->firstArray($response['json']['tracking_data'] ?? $response['json']);

        return new ShiprocketTrackResult(
            provider: $this->provider(),
            status: $this->scalar($tracking['shipment_status'] ?? $tracking['status'] ?? 'unknown') ?? 'unknown',
            activities: is_array($tracking['shipment_track'] ?? null) ? $tracking['shipment_track'] : [],
            externalShipmentId: $externalShipmentId,
            awb: $this->scalar($tracking['awb'] ?? $tracking['awb_code'] ?? null),
        );
    }

    public function cancelOrders(array $externalOrderIds): ShiprocketCancelResult
    {
        $this->send('post', '/orders/cancel', [
            'ids' => array_values($externalOrderIds),
        ]);

        return new ShiprocketCancelResult(
            provider: $this->provider(),
            status: 'cancelled',
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{status: int, json: array<string, mixed>}
     */
    private function send(
        string $method,
        string $path,
        array $data = [],
        bool $authenticated = true,
        bool $throwOnClientError = true,
    ): array {
        $this->assertConfigured();

        try {
            $request = $this->client();
            if ($authenticated) {
                $request = $request->withToken($this->token());
            }

            $response = $method === 'get'
                ? $request->get($this->url($path), $data)
                : $request->{$method}($this->url($path), $data);
        } catch (ConnectionException $exception) {
            throw new ShiprocketRetryableException(
                'Shiprocket request timed out or could not connect: '.$exception->getMessage(),
            );
        } catch (ShiprocketRetryableException|ShiprocketNonRetryableException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ShiprocketRetryableException('Shiprocket request failed: '.$exception->getMessage());
        }

        return $this->interpret($response, $throwOnClientError);
    }

    /**
     * @return array{status: int, json: array<string, mixed>}
     */
    private function interpret(Response $response, bool $throwOnClientError = true): array
    {
        $status = $response->status();
        $json = $response->json();
        $payload = is_array($json) ? $json : [];

        if ($status === 429 || $status >= 500) {
            throw new ShiprocketRetryableException(
                $this->errorMessage($payload, 'Shiprocket returned HTTP '.$status, $status),
            );
        }

        if ($status === 401 && $this->token !== null) {
            $this->token = null;
            throw new ShiprocketRetryableException('Shiprocket token was rejected. Retry after a fresh login.');
        }

        if ($status >= 400) {
            if (! $throwOnClientError) {
                return ['status' => $status, 'json' => $payload];
            }

            throw new ShiprocketNonRetryableException(
                $this->errorMessage($payload, 'Shiprocket returned HTTP '.$status, $status),
            );
        }

        return ['status' => $status, 'json' => $payload];
    }

    /**
     * Provider HTTP 400 plus the observed `message`/`error`/`msg` field.
     * Official order status 12 is "Pickup Queue"; no separate error code was present
     * on the production RDE318421 response that Desk displayed.
     *
     * @param  array<string, mixed>  $json
     */
    private function isAlreadyQueuedPickup(int $httpStatus, array $json): bool
    {
        if ($httpStatus !== 400) {
            return false;
        }

        $message = $this->scalar($json['message'] ?? $json['error'] ?? $json['msg'] ?? null);
        if ($message === null) {
            return false;
        }

        return strcasecmp(trim($message), 'Already in Pickup Queue') === 0;
    }

    private function token(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }

        return $this->acquireToken()->token;
    }

    private function client(): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->connectTimeout((int) config('shipping.connect_timeout_seconds', 5))
            ->timeout((int) config('shipping.timeout_seconds', 15));
    }

    private function url(string $path): string
    {
        return rtrim((string) config('shipping.base_url'), '/').'/'.ltrim($path, '/');
    }

    private function assertConfigured(): void
    {
        $email = trim((string) config('shipping.api_email'));
        $password = trim((string) config('shipping.api_password'));
        if ($email === '' || $password === '') {
            throw new ShiprocketDisabledException('Shiprocket API credentials are not configured. No HTTP was sent.');
        }
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>|null
     */
    private function firstSearchRow(array $json, string $search): ?array
    {
        $rows = $json['data'] ?? $json['orders'] ?? null;
        if (! is_array($rows)) {
            return null;
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $merchant = $this->scalar($row['channel_order_id'] ?? $row['order_id'] ?? null);
            if ($merchant === $search || $this->scalar($row['id'] ?? null) === $search) {
                return $row;
            }
        }

        $first = $rows[0] ?? null;

        return is_array($first) ? $first : null;
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function courierOptionsFromJson(array $json): ShiprocketCourierOptionsResult
    {
        $data = $this->firstArray($json['data'] ?? $json);
        $companies = $data['available_courier_companies'] ?? null;
        if (! is_array($companies)) {
            return new ShiprocketCourierOptionsResult(
                provider: $this->provider(),
                status: 'rejected',
                error: $this->errorMessage($json, 'Shiprocket returned no courier options.'),
                retryable: false,
            );
        }

        $recommendedId = $this->scalar(
            $data['recommended_courier_company_id'] ?? $data['shiprocket_recommended_courier_id'] ?? null,
        );

        $options = [];
        foreach ($companies as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = $this->scalar($row['courier_company_id'] ?? $row['courier_id'] ?? $row['id'] ?? null);
            if ($id === null) {
                continue;
            }

            $rowRecommended = $this->rowIsProviderRecommended($row, $recommendedId, $id);
            $options[] = new ShiprocketCourierOption(
                courierId: $id,
                courierName: $this->scalar($row['courier_name'] ?? $row['name'] ?? null),
                rate: $this->presentNumber($row['freight_charge'] ?? $row['rate'] ?? null),
                coverageCharge: $this->presentNumber($row['coverage_charges'] ?? $row['coverage_charge'] ?? null),
                estimatedDelivery: $this->scalar(
                    $row['etd'] ?? $row['estimated_delivery_days'] ?? $row['estimated_delivery'] ?? null,
                ),
                codAvailable: $this->presentBool($row['cod'] ?? $row['is_cod'] ?? $row['cod_available'] ?? null),
                prepaidAvailable: $this->presentBool($row['prepaid'] ?? $row['is_prepaid'] ?? $row['prepaid_available'] ?? null),
                providerRecommended: $rowRecommended,
                courierType: $this->scalar($row['courier_type'] ?? $row['courierType'] ?? null),
                mode: $this->scalar($row['mode'] ?? $row['shipping_mode'] ?? null),
            );
        }

        $recommendationReturned = $recommendedId !== null;
        foreach ($options as $option) {
            if ($option->providerRecommended) {
                $recommendationReturned = true;
                break;
            }
        }

        return new ShiprocketCourierOptionsResult(
            provider: $this->provider(),
            status: 'listed',
            options: $options,
            recommendedCourierId: $recommendedId,
            recommendationReturned: $recommendationReturned,
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function rowIsProviderRecommended(array $row, ?string $recommendedId, string $id): bool
    {
        if ($recommendedId !== null && $recommendedId === $id) {
            return true;
        }

        foreach (['recommended', 'recommended_by_shiprocket', 'is_recommended'] as $key) {
            if (array_key_exists($key, $row) && $this->presentBool($row[$key]) === true) {
                return true;
            }
        }

        return false;
    }

    private function presentNumber(mixed $value): ?string
    {
        if ($value === null || $value === '' || is_array($value) || ! is_numeric($value)) {
            return null;
        }

        return (string) $value;
    }

    private function presentBool(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return ((int) $value) === 1;
        }

        $text = strtolower(trim((string) $value));

        return match ($text) {
            '1', 'true', 'yes' => true,
            '0', 'false', 'no' => false,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $json
     */
    /**
     * Operator-safe provider message. Field errors are included; tokens and secrets are not.
     *
     * @param  array<string, mixed>  $json
     */
    private function errorMessage(array $json, string $fallback, ?int $httpStatus = null): string
    {
        $parts = [];
        $statusCode = $this->scalar($json['status_code'] ?? null);
        if ($httpStatus !== null && $httpStatus >= 400) {
            $parts[] = 'HTTP '.$httpStatus;
        } elseif ($statusCode !== null) {
            $parts[] = 'status '.$statusCode;
        }

        $summary = null;
        foreach (['message', 'error', 'msg'] as $key) {
            $value = $this->scalar($json[$key] ?? null);
            if ($value !== null) {
                $summary = $this->sanitizeProviderText($value);
                break;
            }
        }
        $parts[] = $summary ?? $fallback;

        $fields = $this->safeFieldErrors($json['errors'] ?? null);
        if ($fields !== '') {
            $parts[] = $fields;
        }

        return implode(' — ', array_values(array_filter($parts)));
    }

    private function safeFieldErrors(mixed $errors): string
    {
        if (! is_array($errors) || $errors === []) {
            return '';
        }

        $out = [];
        foreach ($errors as $field => $messages) {
            $name = preg_replace('/[^A-Za-z0-9_]/', '', (string) $field) ?? '';
            if ($name === '' || $this->looksSensitiveField($name)) {
                continue;
            }

            $texts = is_array($messages) ? $messages : [$messages];
            $clean = [];
            foreach ($texts as $message) {
                if (! is_scalar($message)) {
                    continue;
                }
                $text = $this->sanitizeProviderText(trim((string) $message));
                if ($text !== '') {
                    $clean[] = $text;
                }
            }
            if ($clean === []) {
                continue;
            }

            $out[] = $name.': '.implode('; ', $clean);
        }

        return implode(' · ', $out);
    }

    private function looksSensitiveField(string $field): bool
    {
        $normalized = strtolower($field);

        return in_array($normalized, ['password', 'token', 'authorization', 'secret', 'api_key', 'apikey'], true);
    }

    private function sanitizeProviderText(string $text): string
    {
        $text = preg_replace('/bearer\s+[A-Za-z0-9._\-]+/i', '[redacted]', $text) ?? $text;
        $text = preg_replace('/\b[A-Za-z0-9_-]{20,}\.[A-Za-z0-9._-]{10,}\b/', '[redacted]', $text) ?? $text;

        return trim($text);
    }

    private function providerNumericId(string $id): int|string
    {
        $trimmed = trim($id);

        return ctype_digit($trimmed) ? (int) $trimmed : $trimmed;
    }

    private function scalar(mixed $value): ?string
    {
        if ($value === null || is_array($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /**
     * @return array<string, mixed>
     */
    private function firstArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        if ($value !== [] && array_is_list($value) && is_array($value[0] ?? null)) {
            return $value[0];
        }

        return $value;
    }
}
