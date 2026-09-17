<?php

namespace App\Services\RadiumBox;

use App\Contracts\Refunds\WalletRefundReversalClient;
use App\Models\RefundRequest;
use App\Support\Money\WalletMoney;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class RadiumBoxWalletRefundReversalClient implements WalletRefundReversalClient
{
    public const REVERSAL_PATH = '/api/integrations/v1/wallet-refund-reversals';

    public function isConfigured(): bool
    {
        if (! config('radiumbox.wallet_refund_reversal_enabled')) {
            return false;
        }

        $config = config('order_lookup.spokes.radiumbox_com', []);

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
                'refund' => 'RadiumBox wallet refund reversal is not configured.',
            ]);
        }

        $baseUrl = $this->baseUrl();
        if ($baseUrl === null) {
            throw ValidationException::withMessages([
                'refund' => 'RadiumBox storefront base URL is not configured for wallet refund reversals.',
            ]);
        }

        try {
            $request = Http::baseUrl($baseUrl)
                ->acceptJson()
                ->withToken($this->token())
                ->connectTimeout((int) config('order_lookup.spokes.radiumbox_com.connect_timeout_seconds', 3))
                ->timeout((int) config('order_lookup.spokes.radiumbox_com.timeout_seconds', 8));

            $host = trim((string) config('order_lookup.spokes.radiumbox_com.host', ''));
            if ($host !== '') {
                $request = $request->withHeaders(['Host' => $host]);
            }

            $body = array_filter([
                'desk_refund_reference' => (string) $refund->reference_no,
                'order_id' => $orderId,
                'amount' => $amount,
                'currency' => 'INR',
                'idempotency_key' => $idempotencyKey,
                'original_wallet_transaction_id' => $refund->effectiveTransactionId(),
                'customer_email' => $customerEmail,
            ], fn ($value) => $value !== null && $value !== '');

            $response = $request->post(self::REVERSAL_PATH, $body);

            $payload = $response->json();
            if (! is_array($payload)) {
                throw ValidationException::withMessages([
                    'refund' => 'RadiumBox wallet reversal API returned an invalid response.',
                ]);
            }

            if ($response->failed()) {
                $message = is_string($payload['message'] ?? null)
                    ? $payload['message']
                    : 'RadiumBox wallet reversal API request failed.';

                throw ValidationException::withMessages([
                    'refund' => $message,
                ]);
            }

            $walletReference = data_get($payload, 'data.wallet_reversal_reference')
                ?? data_get($payload, 'data.wallet_reference');
            $walletTransactionId = data_get($payload, 'data.wallet_reversal_transaction_id')
                ?? data_get($payload, 'data.wallet_transaction_id');
            $debit = data_get($payload, 'data.debit');

            if (! is_string($walletReference) || $walletReference === '' || ! is_numeric($walletTransactionId)) {
                throw ValidationException::withMessages([
                    'refund' => 'RadiumBox wallet reversal API did not return wallet debit details.',
                ]);
            }

            if ($debit !== null) {
                $normalizedDebit = WalletMoney::normalize($debit);
                if ($normalizedDebit === null || bccomp($normalizedDebit, $amount, WalletMoney::SCALE) !== 0) {
                    throw ValidationException::withMessages([
                        'refund' => 'RadiumBox wallet reversal API did not return wallet debit details.',
                    ]);
                }
            }

            return [
                'wallet_reversal_reference' => $walletReference,
                'wallet_reversal_transaction_id' => (string) $walletTransactionId,
                'balance' => (string) data_get($payload, 'data.balance', '0.00'),
            ];
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (ConnectionException|RequestException $exception) {
            throw ValidationException::withMessages([
                'refund' => 'RadiumBox wallet reversal API is temporarily unavailable.',
            ]);
        }
    }

    private function token(): string
    {
        return trim((string) config('order_lookup.spokes.radiumbox_com.token', ''));
    }

    private function baseUrl(): ?string
    {
        $baseUrl = rtrim((string) config('order_lookup.spokes.radiumbox_com.base_url', ''), '/');
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
