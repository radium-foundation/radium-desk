<?php

namespace App\Services\StatutoryInvoice\Whitebooks;

use App\Contracts\StatutoryInvoice\BuyerGstinVerificationGateway;
use App\Enums\BuyerGstVerificationStatus;
use App\Services\StatutoryInvoice\BuyerGstin;
use App\Services\StatutoryInvoice\Data\BuyerGstinVerificationResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * WhiteBooks GST Public API: GET /public/search (Search Taxpayer).
 * Uses the same client credentials as statutory e-invoice GSP config.
 */
final class WhitebooksBuyerGstinVerificationGateway implements BuyerGstinVerificationGateway
{
    public const SEARCH_PATH = '/public/search';

    public function __construct(
        private readonly WhitebooksGstTaxpayerSearchResponseMapper $mapper,
    ) {}

    public function provider(): string
    {
        return 'whitebooks';
    }

    public function isConfigured(): bool
    {
        $config = config('statutory_invoices.buyer_gstin_verification', []);

        return (bool) ($config['enabled'] ?? false)
            && $this->clientId() !== ''
            && $this->clientSecret() !== ''
            && $this->email() !== ''
            && $this->baseUrl() !== '';
    }

    public function verify(string $gstin): BuyerGstinVerificationResult
    {
        $normalized = BuyerGstin::normalize($gstin);
        if ($normalized === null || ! BuyerGstin::isValid($normalized)) {
            return new BuyerGstinVerificationResult(
                status: BuyerGstVerificationStatus::Failed,
                gstin: $normalized,
                reviewReason: 'invalid_gstin_format',
            );
        }

        if (! $this->isConfigured()) {
            return new BuyerGstinVerificationResult(
                status: BuyerGstVerificationStatus::ReviewRequired,
                gstin: $normalized,
                reviewReason: 'verification_provider_unconfigured',
            );
        }

        $correlationId = (string) Str::uuid();

        try {
            $response = Http::baseUrl($this->baseUrl())
                ->acceptJson()
                ->timeout((int) config('statutory_invoices.einvoice.timeout_seconds', 30))
                ->withHeaders([
                    'client_id' => $this->clientId(),
                    'client_secret' => $this->clientSecret(),
                ])
                ->get(self::SEARCH_PATH, [
                    'email' => $this->email(),
                    'gstin' => $normalized,
                ]);

            if ($response->status() === 429) {
                return new BuyerGstinVerificationResult(
                    status: BuyerGstVerificationStatus::Pending,
                    gstin: $normalized,
                    reviewReason: 'provider_rate_limited',
                    correlationId: $correlationId,
                );
            }

            if ($response->status() >= 500 || $response->status() === 408) {
                return new BuyerGstinVerificationResult(
                    status: BuyerGstVerificationStatus::Pending,
                    gstin: $normalized,
                    reviewReason: 'provider_unavailable',
                    correlationId: $correlationId,
                );
            }

            $body = $response->json();
            if (! is_array($body)) {
                return new BuyerGstinVerificationResult(
                    status: BuyerGstVerificationStatus::ReviewRequired,
                    gstin: $normalized,
                    reviewReason: 'provider_malformed_response',
                    correlationId: $correlationId,
                );
            }

            return $this->mapper->map($normalized, $body, $correlationId);
        } catch (ConnectionException $exception) {
            Log::notice('buyer_gstin_verification.whitebooks_timeout', [
                'gstin_prefix' => substr($normalized, 0, 2),
                'correlation_id' => $correlationId,
                'exception' => $exception::class,
            ]);

            return new BuyerGstinVerificationResult(
                status: BuyerGstVerificationStatus::Pending,
                gstin: $normalized,
                reviewReason: 'provider_timeout',
                correlationId: $correlationId,
            );
        } catch (Throwable $exception) {
            Log::warning('buyer_gstin_verification.whitebooks_error', [
                'gstin_prefix' => substr($normalized, 0, 2),
                'correlation_id' => $correlationId,
                'exception' => $exception::class,
            ]);

            return new BuyerGstinVerificationResult(
                status: BuyerGstVerificationStatus::ReviewRequired,
                gstin: $normalized,
                reviewReason: 'provider_error',
                correlationId: $correlationId,
            );
        }
    }

    private function baseUrl(): string
    {
        $base = config('statutory_invoices.einvoice.gsp_base_url');

        return is_string($base) ? rtrim(trim($base), '/') : '';
    }

    private function clientId(): string
    {
        $value = config('statutory_invoices.einvoice.gsp_client_id');

        return is_string($value) ? trim($value) : '';
    }

    private function clientSecret(): string
    {
        $value = config('statutory_invoices.einvoice.gsp_client_secret');

        return is_string($value) ? trim($value) : '';
    }

    private function email(): string
    {
        $value = config('statutory_invoices.einvoice.gsp_email');

        return is_string($value) ? trim($value) : '';
    }
}
