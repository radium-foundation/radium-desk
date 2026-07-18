<?php

namespace App\Services\IncomingEmail\Gmail;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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
        $jwt = $this->buildJwt($credentials, $mailbox);

        $response = Http::asForm()
            ->timeout((int) config('inbound_email.gmail.timeout_seconds', 20))
            ->connectTimeout((int) config('inbound_email.gmail.connect_timeout_seconds', 5))
            ->post((string) config('inbound_email.gmail.token_url'), [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'Gmail OAuth token request failed for %s: HTTP %d',
                $mailbox,
                $response->status(),
            ));
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
     */
    private function buildJwt(array $credentials, string $mailbox): string
    {
        $now = time();
        $scopes = config('inbound_email.gmail.scopes', [
            'https://www.googleapis.com/auth/gmail.readonly',
        ]);

        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $payload = [
            'iss' => $credentials['client_email'],
            'sub' => $mailbox,
            'scope' => is_array($scopes) ? implode(' ', $scopes) : (string) $scopes,
            'aud' => (string) config('inbound_email.gmail.token_url'),
            'iat' => $now,
            'exp' => $now + 3600,
        ];

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
}
