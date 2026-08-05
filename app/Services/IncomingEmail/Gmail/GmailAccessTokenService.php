<?php

namespace App\Services\IncomingEmail\Gmail;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Google service-account JWT bearer flow with domain-wide delegation.
 * Impersonates the mailbox user (sub claim) for gmail.readonly.
 */
class GmailAccessTokenService
{
    public function tokenForMailbox(string $mailbox): string
    {
        $mailbox = strtolower(trim($mailbox));
        $cacheKey = 'gmail.access_token.'.sha1($mailbox);

        $cached = Cache::get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $credentials = $this->loadCredentials();
        $now = time();
        $jwt = $this->buildJwt($credentials, $mailbox, $now);

        $response = Http::asForm()
            ->timeout((int) config('inbound_email.gmail.timeout_seconds', 20))
            ->connectTimeout((int) config('inbound_email.gmail.connect_timeout_seconds', 5))
            ->post((string) config('inbound_email.gmail.token_url'), [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

        if (! $response->successful()) {
            throw $this->tokenEndpointFailure($mailbox, $response);
        }

        $accessToken = (string) $response->json('access_token', '');
        $expiresIn = max(60, (int) $response->json('expires_in', 3600));

        if ($accessToken === '') {
            throw new RuntimeException('Gmail OAuth token response missing access_token.');
        }

        // Refresh slightly before expiry.
        Cache::put($cacheKey, $accessToken, now()->addSeconds($expiresIn - 60));

        return $accessToken;
    }

    /**
     * @return array{client_email: string, private_key: string}
     */
    private function loadCredentials(): array
    {
        $pathOrJson = (string) config('inbound_email.gmail.service_account_json', '');

        if ($pathOrJson === '') {
            throw new RuntimeException('GOOGLE_SERVICE_ACCOUNT_JSON is not configured.');
        }

        if (is_file($pathOrJson)) {
            $contents = file_get_contents($pathOrJson);

            if ($contents === false) {
                throw new RuntimeException('Unable to read Google service account JSON file.');
            }
        } else {
            $contents = $pathOrJson;
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded)
            || empty($decoded['client_email'])
            || empty($decoded['private_key'])) {
            throw new RuntimeException('Google service account JSON is invalid (need client_email + private_key).');
        }

        return [
            'client_email' => (string) $decoded['client_email'],
            'private_key' => (string) $decoded['private_key'],
        ];
    }

    /**
     * @param  array{client_email: string, private_key: string}  $credentials
     * @return array{iss: string, sub: string, scope: string, aud: string, iat: int, exp: int}
     */
    private function jwtClaims(array $credentials, string $mailbox, int $now): array
    {
        $scopes = config('inbound_email.gmail.scopes', [
            'https://www.googleapis.com/auth/gmail.readonly',
        ]);

        return [
            'iss' => $credentials['client_email'],
            'sub' => $mailbox,
            'scope' => is_array($scopes) ? implode(' ', $scopes) : (string) $scopes,
            'aud' => (string) config('inbound_email.gmail.token_url'),
            'iat' => $now,
            'exp' => $now + 3600,
        ];
    }

    /**
     * @param  array{client_email: string, private_key: string}  $credentials
     */
    private function buildJwt(array $credentials, string $mailbox, ?int $now = null): string
    {
        $now ??= time();
        $payload = $this->jwtClaims($credentials, $mailbox, $now);

        $header = ['alg' => 'RS256', 'typ' => 'JWT'];

        $segments = [
            $this->base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR)),
            $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR)),
        ];

        $signingInput = implode('.', $segments);
        $privateKey = openssl_pkey_get_private($credentials['private_key']);

        if ($privateKey === false) {
            throw new RuntimeException('Unable to parse Google service account private key.');
        }

        $signature = '';
        $signed = openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        if (! $signed) {
            throw new RuntimeException('Unable to sign Google service account JWT.');
        }

        $segments[] = $this->base64UrlEncode($signature);

        return implode('.', $segments);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function tokenEndpointFailure(string $mailbox, Response $response): RuntimeException
    {
        $status = $response->status();
        $safeBody = $this->sanitizeTokenErrorBody($response->body());
        $summary = $this->summarizeTokenError($safeBody);

        Log::error('[GmailInbound] OAuth token endpoint rejected request.', [
            'mailbox' => $mailbox,
            'http_status' => $status,
            'response_body' => $safeBody,
            'error' => $summary['error'],
            'error_description' => $summary['error_description'],
        ]);

        $message = sprintf(
            'Gmail OAuth token request failed for %s: HTTP %d',
            $mailbox,
            $status,
        );

        if ($safeBody !== '') {
            $message .= "\n".$safeBody;
        }

        return new RuntimeException($message);
    }

    /**
     * Keep Google error diagnostics while masking JWT assertions / private keys / tokens.
     */
    private function sanitizeTokenErrorBody(string $rawBody): string
    {
        $rawBody = trim($rawBody);

        if ($rawBody === '') {
            return '';
        }

        $decoded = json_decode($rawBody, true);

        if (is_array($decoded)) {
            $safe = [];

            foreach (['error', 'error_description', 'error_uri', 'error_subtype'] as $key) {
                if (! array_key_exists($key, $decoded)) {
                    continue;
                }

                $value = $decoded[$key];
                if (is_scalar($value) || $value === null) {
                    $safe[$key] = $this->maskSensitiveString((string) $value);
                }
            }

            // Preserve other non-sensitive scalar keys for unexpected Google payloads.
            foreach ($decoded as $key => $value) {
                if (array_key_exists($key, $safe)) {
                    continue;
                }

                $normalized = strtolower((string) $key);
                if ($this->isSensitiveCredentialKey($normalized)) {
                    $safe[$key] = '[redacted]';
                    continue;
                }

                if (is_scalar($value) || $value === null) {
                    $safe[$key] = $this->maskSensitiveString((string) $value);
                }
            }

            $encoded = json_encode($safe, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            return is_string($encoded) ? $encoded : '';
        }

        return $this->maskSensitiveString(mb_substr($rawBody, 0, 2000));
    }

    /**
     * @return array{error: ?string, error_description: ?string}
     */
    private function summarizeTokenError(string $safeBody): array
    {
        $decoded = json_decode($safeBody, true);

        if (! is_array($decoded)) {
            return ['error' => null, 'error_description' => null];
        }

        return [
            'error' => isset($decoded['error']) && is_scalar($decoded['error'])
                ? (string) $decoded['error']
                : null,
            'error_description' => isset($decoded['error_description']) && is_scalar($decoded['error_description'])
                ? (string) $decoded['error_description']
                : null,
        ];
    }

    private function isSensitiveCredentialKey(string $key): bool
    {
        return in_array($key, [
            'assertion',
            'private_key',
            'private_key_id',
            'client_secret',
            'access_token',
            'refresh_token',
            'id_token',
            'token',
            'password',
            'authorization',
        ], true);
    }

    private function maskSensitiveString(string $value): string
    {
        // JWT / assertion-shaped strings: header.payload.signature
        $value = preg_replace(
            '/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\b/',
            '[redacted-jwt]',
            $value,
        ) ?? $value;

        // PEM private key blocks if Google (or a proxy) ever echoed them.
        $value = preg_replace(
            '/-----BEGIN [A-Z ]*PRIVATE KEY-----[\s\S]*?-----END [A-Z ]*PRIVATE KEY-----/',
            '[redacted-private-key]',
            $value,
        ) ?? $value;

        return $value;
    }
}
