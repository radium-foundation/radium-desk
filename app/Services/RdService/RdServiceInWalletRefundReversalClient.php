<?php

namespace App\Services\RdService;

use App\Contracts\Refunds\WalletRefundReversalClient;
use App\Models\RefundRequest;
use App\Support\Money\WalletMoney;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Desk client for rdservice.in wallet refund reversal (debit).
 *
 * Requires rdservice.in to deploy POST /api/integrations/v1/wallet-refund-reversals.
 * Fail-closed when {@see isConfigured()} is false.
 */
class RdServiceInWalletRefundReversalClient implements WalletRefundReversalClient
{
    public const SOURCE_SYSTEM = 'radium_desk';

    public const CURRENCY = 'INR';

    public const REVERSAL_PATH = '/api/integrations/v1/wallet-refund-reversals';

    public function isConfigured(): bool
    {
        if (! config('rdservice_in.wallet_refund_reversal_enabled')) {
            return false;
        }

        $config = config('order_lookup.spokes.rdservice_in', []);

        return (bool) ($config['enabled'] ?? false)
            && $this->token() !== ''
            && $this->baseUrl() !== null;
    }

    public function reverseWalletRefund(
        RefundRequest $refund,
        string $orderId,
        string $amount,
        string $idempotencyKey,
        ?string $customerEmail = null,
    ): array {
        if (! $this->isConfigured()) {
            throw ValidationException::withMessages([
                'refund' => 'rdservice.in wallet refund reversal is not configured. Deploy the rdservice.in reversal API before revoking wallet refunds.',
            ]);
        }

        $baseUrl = $this->baseUrl();
        if ($baseUrl === null) {
            throw ValidationException::withMessages([
                'refund' => 'rdservice.in base URL is not configured for wallet refund reversals.',
            ]);
        }

        $deskRefundReference = (string) $refund->reference_no;
        $originalWalletTransactionId = $refund->effectiveTransactionId();

        try {
            $request = Http::baseUrl($baseUrl)
                ->acceptJson()
                ->withToken($this->token())
                ->connectTimeout((int) config('order_lookup.spokes.rdservice_in.connect_timeout_seconds', 3))
                ->timeout((int) config('order_lookup.spokes.rdservice_in.timeout_seconds', 8));

            $host = trim((string) config('order_lookup.spokes.rdservice_in.host', ''));
            if ($host !== '') {
                $request = $request->withHeaders(['Host' => $host]);
            }

            $body = [
                'source_system' => self::SOURCE_SYSTEM,
                'desk_refund_reference' => $deskRefundReference,
                'order_id' => $orderId,
                'amount' => $amount,
                'currency' => self::CURRENCY,
                'idempotency_key' => $idempotencyKey,
            ];

            if ($originalWalletTransactionId !== null) {
                $body['original_wallet_transaction_id'] = $originalWalletTransactionId;
            }

            if (is_string($customerEmail) && trim($customerEmail) !== '') {
                $body['customer_email'] = trim($customerEmail);
            }

            $response = $request->post(self::REVERSAL_PATH, $body);

            $payload = $response->json();
            if (! is_array($payload)) {
                throw ValidationException::withMessages([
                    'refund' => 'rdservice.in wallet reversal API returned an invalid response.',
                ]);
            }

            if ($response->failed()) {
                $message = is_string($payload['message'] ?? null)
                    ? $payload['message']
                    : 'rdservice.in wallet reversal API request failed.';

                throw ValidationException::withMessages([
                    'refund' => $message,
                ]);
            }

            return $this->parseSuccessfulReversalResponse($payload, $deskRefundReference, $amount);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (ConnectionException|RequestException $exception) {
            throw ValidationException::withMessages([
                'refund' => 'rdservice.in wallet reversal API is temporarily unavailable.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     wallet_reversal_reference: string,
     *     wallet_reversal_transaction_id: string,
     *     balance: string,
     * }
     */
    private function parseSuccessfulReversalResponse(
        array $payload,
        string $deskRefundReference,
        string $amount,
    ): array {
        $data = data_get($payload, 'data');
        if (! is_array($data)) {
            throw ValidationException::withMessages([
                'refund' => 'rdservice.in wallet reversal API did not return wallet debit details.',
            ]);
        }

        $responseReference = data_get($data, 'desk_refund_reference');
        if ($responseReference !== null && (string) $responseReference !== $deskRefundReference) {
            throw ValidationException::withMessages([
                'refund' => 'rdservice.in wallet reversal API did not return wallet debit details.',
            ]);
        }

        $responseDebit = data_get($data, 'debit');
        if ($responseDebit !== null) {
            $normalizedDebit = WalletMoney::normalize($responseDebit);
            if ($normalizedDebit === null || bccomp($normalizedDebit, $amount, WalletMoney::SCALE) !== 0) {
                throw ValidationException::withMessages([
                    'refund' => 'rdservice.in wallet reversal API did not return wallet debit details.',
                ]);
            }
        }

        $walletReference = $this->normalizeReference(data_get($data, 'wallet_reversal_reference')
            ?? data_get($data, 'wallet_reference'));
        $walletTransactionId = $this->normalizeTransactionId(data_get($data, 'wallet_reversal_transaction_id')
            ?? data_get($data, 'wallet_transaction_id'));

        if ($walletReference === null || $walletTransactionId === null) {
            throw ValidationException::withMessages([
                'refund' => 'rdservice.in wallet reversal API did not return wallet debit details.',
            ]);
        }

        return [
            'wallet_reversal_reference' => $walletReference,
            'wallet_reversal_transaction_id' => $walletTransactionId,
            'balance' => (string) data_get($data, 'balance', '0.00'),
        ];
    }

    private function normalizeReference(mixed $value): ?string
    {
        if (is_int($value)) {
            return $value > 0 ? (string) $value : null;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed !== '' ? $trimmed : null;
        }

        return null;
    }

    private function normalizeTransactionId(mixed $value): ?string
    {
        if (is_int($value)) {
            return $value > 0 ? (string) $value : null;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed !== '' ? $trimmed : null;
        }

        return null;
    }

    private function token(): string
    {
        return trim((string) config('order_lookup.spokes.rdservice_in.token', ''));
    }

    private function baseUrl(): ?string
    {
        $baseUrl = rtrim((string) config('order_lookup.spokes.rdservice_in.base_url', ''), '/');
        if ($baseUrl === '') {
            return null;
        }

        $parsed = parse_url($baseUrl);
        $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
        $host = strtolower((string) ($parsed['host'] ?? ''));
        $isLoopback = in_array($host, ['127.0.0.1', 'localhost', '::1'], true);

        if ($scheme === 'https' || ($scheme === 'http' && $isLoopback)) {
            return $baseUrl;
        }

        return null;
    }
}
