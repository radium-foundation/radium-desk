<?php

namespace App\Services\Bonvoice;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class BonvoiceAuthentication
{
    private const CACHE_KEY = 'bonvoice.api.auth_token';

    private const LOCK_KEY = 'bonvoice.api.auth_refresh';

    private const LOCK_SECONDS = 15;

    private const LOCK_WAIT_SECONDS = 10;

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        $token = $this->token();

        return [
            'Authorization' => 'Token '.$token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
    }

    public function token(): string
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        return $this->refreshTokenUnderLock();
    }

    public function forgetToken(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, mixed>
     */
    public function redactHeadersForLogging(array $headers): array
    {
        $redacted = $headers;

        foreach (['Authorization', 'authorization'] as $key) {
            if (array_key_exists($key, $redacted)) {
                $redacted[$key] = 'Token ********';
            }
        }

        return $redacted;
    }

    private function refreshTokenUnderLock(): string
    {
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);

        return $lock->block(self::LOCK_WAIT_SECONDS, function (): string {
            $cached = Cache::get(self::CACHE_KEY);

            if (is_string($cached) && $cached !== '') {
                return $cached;
            }

            [$token, $expiresAt] = $this->authenticate();

            if ($expiresAt !== null) {
                Cache::put(self::CACHE_KEY, $token, $expiresAt);
            } else {
                Cache::forever(self::CACHE_KEY, $token);
            }

            return $token;
        });
    }

    /**
     * @return array{0: string, 1: ?Carbon}
     */
    private function authenticate(): array
    {
        $username = (string) config('bonvoice.click_to_call.username');
        $password = (string) config('bonvoice.click_to_call.password');

        if ($username === '' || $password === '') {
            throw new RuntimeException('BonVoice API credentials are not configured.');
        }

        $url = $this->baseUrl().'/usermanagement/external-auth/';

        try {
            Log::debug('[BonVoice] Auth request', [
                'url' => $url,
            ]);

            $response = $this->httpClient()
                ->post('/usermanagement/external-auth/', [
                    'username' => $username,
                    'password' => $password,
                ]);
        } catch (ConnectionException $exception) {
            Log::warning('[BonVoice] Auth connection failed', [
                'message' => $exception->getMessage(),
            ]);

            throw new RuntimeException('Unable to reach BonVoice. Please try again.', 0, $exception);
        } catch (RequestException $exception) {
            Log::warning('[BonVoice] Auth request failed', [
                'status' => $exception->response?->status(),
                'message' => $exception->getMessage(),
            ]);

            throw new RuntimeException('BonVoice authentication failed.', 0, $exception);
        }

        if ($response->failed()) {
            throw new RuntimeException('BonVoice authentication was rejected.');
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('BonVoice authentication returned an invalid response.');
        }

        $token = data_get($payload, 'data.token');

        if (! is_string($token) || trim($token) === '') {
            $message = data_get($payload, 'message');

            throw new RuntimeException(is_string($message) && $message !== ''
                ? $message
                : 'BonVoice authentication response is missing token.');
        }

        return [trim($token), $this->resolveTokenExpiry($payload)];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveTokenExpiry(array $payload): ?Carbon
    {
        foreach ([
            'data.expires_at',
            'data.expiresAt',
            'data.token_expiry',
            'data.tokenExpiry',
            'expires_at',
            'expiresAt',
        ] as $key) {
            $value = data_get($payload, $key);

            if (is_string($value) && trim($value) !== '') {
                try {
                    return Carbon::parse($value)->subSeconds(60);
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        foreach ([
            'data.expires_in',
            'data.expiresIn',
            'expires_in',
            'expiresIn',
        ] as $key) {
            $seconds = data_get($payload, $key);

            if (is_numeric($seconds) && (int) $seconds > 0) {
                return now()->addSeconds((int) $seconds - 60);
            }
        }

        return null;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('bonvoice.click_to_call.base_url'), '/');
    }

    private function httpClient()
    {
        return Http::baseUrl($this->baseUrl())
            ->acceptJson()
            ->asJson()
            ->connectTimeout((int) config('bonvoice.click_to_call.connect_timeout_seconds', 5))
            ->timeout((int) config('bonvoice.click_to_call.timeout_seconds', 15));
    }
}
