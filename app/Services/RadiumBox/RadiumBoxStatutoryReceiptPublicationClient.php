<?php

namespace App\Services\RadiumBox;

use App\Models\StatutoryInvoice;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class RadiumBoxStatutoryReceiptPublicationClient
{
    public const ENDPOINT_PATH = '/api/integrations/v1/desk-statutory-invoices';

    public function isConfigured(): bool
    {
        $config = config('order_lookup.spokes.radiumbox_com', []);

        return (bool) ($config['enabled'] ?? false)
            && $this->token() !== ''
            && $this->baseUrl() !== null;
    }

    public function publish(StatutoryInvoice $invoice, string $httpIdempotencyKey): RadiumBoxStatutoryReceiptPublicationResult
    {
        if (! $this->isConfigured()) {
            throw new RadiumBoxStatutoryReceiptPublicationPermanentFailureException(
                'RadiumBox statutory receipt publication is not configured.',
            );
        }

        $baseUrl = $this->baseUrl();
        if ($baseUrl === null) {
            throw new RadiumBoxStatutoryReceiptPublicationPermanentFailureException(
                'RadiumBox storefront base URL is not configured.',
            );
        }

        $body = RadiumBoxStatutoryReceiptPublicationPayload::fromInvoice($invoice);
        $timeout = (int) config('radiumbox.statutory_receipt_publish.timeout_seconds', 15);
        $connectTimeout = (int) config('radiumbox.statutory_receipt_publish.connect_timeout_seconds', 3);

        try {
            $request = Http::baseUrl($baseUrl)
                ->acceptJson()
                ->asJson()
                ->withToken($this->token())
                ->withHeaders(['Idempotency-Key' => $httpIdempotencyKey])
                ->connectTimeout($connectTimeout)
                ->timeout($timeout);

            $host = trim((string) config('order_lookup.spokes.radiumbox_com.host', ''));
            if ($host !== '') {
                $request = $request->withHeaders(['Host' => $host]);
            }

            $response = $request->post(self::ENDPOINT_PATH, $body);
            $payload = $response->json();
            if (! is_array($payload)) {
                $payload = [];
            }

            return $this->interpretResponse($response->status(), $payload);
        } catch (ConnectionException|RequestException $exception) {
            throw new RadiumBoxStatutoryReceiptPublicationRetryableException(
                'RadiumBox statutory receipt API is temporarily unavailable.',
                previous: $exception,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function interpretResponse(int $status, array $payload): RadiumBoxStatutoryReceiptPublicationResult
    {
        $message = is_string($payload['message'] ?? null) ? trim($payload['message']) : null;
        $eligibility = is_string($payload['eligibility'] ?? null) ? trim($payload['eligibility']) : null;

        if ($status >= 200 && $status < 300) {
            $receipt = is_array($payload['receipt'] ?? null) ? $payload['receipt'] : [];
            $receiptId = is_string($receipt['receipt_id'] ?? null) ? trim($receipt['receipt_id']) : null;
            $duplicate = (bool) ($payload['duplicate_delivery'] ?? false);

            if ($receiptId === null || $receiptId === '') {
                return new RadiumBoxStatutoryReceiptPublicationResult(
                    httpStatus: $status,
                    boxReceiptId: null,
                    duplicateDelivery: $duplicate,
                    eligibilityCode: $eligibility,
                    message: 'Successful response missing receipt_id.',
                    retryable: true,
                );
            }

            return new RadiumBoxStatutoryReceiptPublicationResult(
                httpStatus: $status,
                boxReceiptId: $receiptId,
                duplicateDelivery: $duplicate,
                eligibilityCode: $eligibility,
                message: $message,
                retryable: false,
            );
        }

        $retryable = $status >= 500;

        return new RadiumBoxStatutoryReceiptPublicationResult(
            httpStatus: $status,
            boxReceiptId: null,
            duplicateDelivery: false,
            eligibilityCode: $eligibility,
            message: $message ?? 'RadiumBox statutory receipt API request failed.',
            retryable: $retryable,
        );
    }

    private function token(): string
    {
        return trim((string) config('order_lookup.spokes.radiumbox_com.token', ''));
    }

    private function baseUrl(): ?string
    {
        $base = rtrim((string) config('order_lookup.spokes.radiumbox_com.base_url', ''), '/');

        return $base !== '' ? $base : null;
    }
}
