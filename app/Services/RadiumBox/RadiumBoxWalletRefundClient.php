<?php

namespace App\Services\RadiumBox;

use App\Support\Money\WalletMoney;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class RadiumBoxWalletRefundClient
{
    public function isConfigured(): bool
    {
        if (! config('radiumbox.wallet_refund_credit_enabled')) {
            return false;
        }

        $config = config('order_lookup.spokes.radiumbox_com', []);

        return (bool) ($config['enabled'] ?? false)
            && $this->token() !== ''
            && $this->baseUrl() !== null;
    }

    /**
     * @return array{
     *     wallet_reference: string,
     *     wallet_transaction_id: int,
     *     balance: float,
     * }
     */
    public function creditWalletRefund(
        string $deskRefundReference,
        string $orderId,
        float $amount,
        ?string $customerEmail = null,
    ): array {
        if (! $this->isConfigured()) {
            throw ValidationException::withMessages([
                'refund' => 'Automated wallet refund credit is not configured.',
            ]);
        }

        $baseUrl = $this->baseUrl();
        if ($baseUrl === null) {
            throw ValidationException::withMessages([
                'refund' => 'RadiumBox storefront base URL is not configured for wallet refunds.',
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

            $response = $request->post('/api/integrations/v1/wallet-refunds', array_filter([
                'desk_refund_reference' => $deskRefundReference,
                'order_id' => $orderId,
                'amount' => round($amount, 2),
                'customer_email' => $customerEmail,
            ], fn ($value) => $value !== null && $value !== ''));

            $payload = $response->json();
            if (! is_array($payload)) {
                throw ValidationException::withMessages([
                    'refund' => 'RadiumBox wallet refund API returned an invalid response.',
                ]);
            }

            if ($response->failed()) {
                $message = is_string($payload['message'] ?? null)
                    ? $payload['message']
                    : 'RadiumBox wallet refund API request failed.';

                throw ValidationException::withMessages([
                    'refund' => $message,
                ]);
            }

            return $this->parseSuccessfulCreditResponse(
                $payload,
                $deskRefundReference,
                round($amount, 2),
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (ConnectionException|RequestException $exception) {
            throw ValidationException::withMessages([
                'refund' => 'RadiumBox wallet refund API is temporarily unavailable.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     wallet_reference: string,
     *     wallet_transaction_id: int,
     *     balance: float,
     * }
     */
    private function parseSuccessfulCreditResponse(
        array $payload,
        string $deskRefundReference,
        float $amount,
    ): array {
        $data = data_get($payload, 'data');
        if (! is_array($data)) {
            throw ValidationException::withMessages([
                'refund' => 'RadiumBox wallet refund API did not return wallet credit details.',
            ]);
        }

        $responseReference = data_get($data, 'desk_refund_reference');
        if ($responseReference !== null && (string) $responseReference !== $deskRefundReference) {
            throw ValidationException::withMessages([
                'refund' => 'RadiumBox wallet refund API did not return wallet credit details.',
            ]);
        }

        $responseCredit = data_get($data, 'credit');
        if ($responseCredit !== null) {
            $normalizedCredit = WalletMoney::normalize($responseCredit);
            $normalizedAmount = WalletMoney::normalize((string) $amount);
            if ($normalizedCredit === null || $normalizedAmount === null
                || bccomp($normalizedCredit, $normalizedAmount, WalletMoney::SCALE) !== 0) {
                throw ValidationException::withMessages([
                    'refund' => 'RadiumBox wallet refund API did not return wallet credit details.',
                ]);
            }
        }

        $walletTransactionId = $this->normalizeWalletTransactionId(data_get($data, 'wallet_transaction_id'));
        $walletReference = $this->normalizeWalletReference(
            data_get($data, 'wallet_reference') ?? data_get($data, 'txnid'),
        );

        if ($walletReference === null && $walletTransactionId !== null) {
            $walletReference = 'RD'.$walletTransactionId;
        }

        if ($walletReference === null || $walletTransactionId === null) {
            throw ValidationException::withMessages([
                'refund' => 'RadiumBox wallet refund API did not return wallet credit details.',
            ]);
        }

        return [
            'wallet_reference' => $walletReference,
            'wallet_transaction_id' => $walletTransactionId,
            'balance' => round((float) data_get($data, 'balance', 0), 2),
        ];
    }

    private function normalizeWalletReference(mixed $value): ?string
    {
        if (is_int($value)) {
            return $value > 0 ? (string) $value : null;
        }

        if (is_float($value)) {
            if ($value <= 0 || $value !== (float) (int) $value) {
                return null;
            }

            return (string) (int) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^(?:0|[1-9]\d*)$/', $trimmed) === 1) {
            return $trimmed;
        }

        if (preg_match('/^[A-Za-z0-9._-]{1,64}$/', $trimmed) === 1) {
            return $trimmed;
        }

        return null;
    }

    private function normalizeWalletTransactionId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_float($value)) {
            if ($value <= 0 || $value !== (float) (int) $value) {
                return null;
            }

            return (int) $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '' || preg_match('/^(?:0|[1-9]\d*)$/', $trimmed) !== 1) {
                return null;
            }

            return (int) $trimmed;
        }

        return null;
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
