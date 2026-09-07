<?php

namespace App\Services\Shipping;

use App\Contracts\Shipping\ShiprocketGateway;
use App\Services\Shipping\Data\ShiprocketAwbResult;
use App\Services\Shipping\Data\ShiprocketCancelResult;
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

        $response = $this->send('post', '/auth/login', [
            'email' => config('shipping.api_email'),
            'password' => config('shipping.api_password'),
        ], authenticated: false);

        $token = trim((string) ($response['json']['token'] ?? ''));
        if ($token === '') {
            throw new ShiprocketNonRetryableException('Shiprocket login did not return a token.');
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
                error: $this->errorMessage($json, 'Shiprocket rejected shipment create.'),
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
            $this->send('post', '/courier/generate/pickup', [
                'shipment_id' => [$externalShipmentId],
            ]);
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

        return new ShiprocketPickupResult(
            provider: $this->provider(),
            status: 'requested',
        );
    }

    public function generateLabel(string $externalShipmentId): ShiprocketDocumentResult
    {
        $response = $this->send('get', '/courier/generate/label', [
            'shipment_id' => $externalShipmentId,
        ]);

        return new ShiprocketDocumentResult(
            provider: $this->provider(),
            status: 'generated',
            url: $this->scalar($response['json']['label_url'] ?? $response['json']['not_created'][0] ?? null),
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
    private function send(string $method, string $path, array $data = [], bool $authenticated = true): array
    {
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

        return $this->interpret($response);
    }

    /**
     * @return array{status: int, json: array<string, mixed>}
     */
    private function interpret(Response $response): array
    {
        $status = $response->status();
        $json = $response->json();
        $payload = is_array($json) ? $json : [];

        if ($status === 429 || $status >= 500) {
            throw new ShiprocketRetryableException(
                $this->errorMessage($payload, 'Shiprocket returned HTTP '.$status),
            );
        }

        if ($status === 401 && $this->token !== null) {
            $this->token = null;
            throw new ShiprocketRetryableException('Shiprocket token was rejected. Retry after a fresh login.');
        }

        if ($status >= 400) {
            throw new ShiprocketNonRetryableException(
                $this->errorMessage($payload, 'Shiprocket returned HTTP '.$status),
            );
        }

        return ['status' => $status, 'json' => $payload];
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
    private function errorMessage(array $json, string $fallback): string
    {
        foreach (['message', 'error', 'msg'] as $key) {
            $value = $this->scalar($json[$key] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        return $fallback;
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
