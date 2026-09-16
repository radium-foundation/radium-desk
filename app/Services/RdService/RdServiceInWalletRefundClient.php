<?php

namespace App\Services\RdService;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class RdServiceInWalletRefundClient
{
    public const SOURCE_SYSTEM = 'radium_desk';

    public const CURRENCY = 'INR';

    public function isConfigured(): bool
    {
        if (! config('rdservice_in.wallet_refund_credit_enabled')) {
            return false;
        }

        $config = config('order_lookup.spokes.rdservice_in', []);

        return (bool) ($config['enabled'] ?? false)
            && $this->token() !== ''
            && $this->baseUrl() !== null;
    }

    /**
     * @return array{
     *     wallet_reference: string,
     *     wallet_transaction_id: int,
     *     balance: string,
     * }
     */
    public function creditWalletRefund(
        string $deskRefundReference,
        string $orderId,
        string $amount,
        ?string $customerEmail = null,
    ): array {
        if (! $this->isConfigured()) {
            throw ValidationException::withMessages([
                'refund' => 'rdservice.in wallet refund credit is not configured.',
            ]);
        }

        $baseUrl = $this->baseUrl();
        if ($baseUrl === null) {
            throw ValidationException::withMessages([
                'refund' => 'rdservice.in base URL is not configured for wallet refunds.',
            ]);
        }

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
            ];
            if (is_string($customerEmail) && trim($customerEmail) !== '') {
                $body['customer_email'] = trim($customerEmail);
            }

            $response = $request->post('/api/integrations/v1/wallet-refunds', $body);

            $payload = $response->json();
            if (! is_array($payload)) {
                throw ValidationException::withMessages([
                    'refund' => 'rdservice.in wallet refund API returned an invalid response.',
                ]);
            }

            if ($response->failed()) {
                $message = is_string($payload['message'] ?? null)
                    ? $payload['message']
                    : 'rdservice.in wallet refund API request failed.';

                throw ValidationException::withMessages([
                    'refund' => $message,
                ]);
            }

            $walletReference = data_get($payload, 'data.wallet_reference');
            $walletTransactionId = data_get($payload, 'data.wallet_transaction_id');

            if (! is_string($walletReference) || $walletReference === '' || ! is_numeric($walletTransactionId)) {
                throw ValidationException::withMessages([
                    'refund' => 'rdservice.in wallet refund API did not return wallet credit details.',
                ]);
            }

            return [
                'wallet_reference' => $walletReference,
                'wallet_transaction_id' => (int) $walletTransactionId,
                'balance' => (string) data_get($payload, 'data.balance', '0.00'),
            ];
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (ConnectionException|RequestException $exception) {
            throw ValidationException::withMessages([
                'refund' => 'rdservice.in wallet refund API is temporarily unavailable.',
            ]);
        }
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
