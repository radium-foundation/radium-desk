<?php

namespace App\Services\WalletRefundOpmConversion;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

final class LegacySpokeWalletRefundOpmClient
{
    /**
     * @param  array<string, mixed>  $spokeConfig  order_lookup.spokes.* entry
     * @return array<string, mixed>
     */
    public function post(string $path, array $body, array $spokeConfig): array
    {
        $baseUrl = rtrim((string) ($spokeConfig['base_url'] ?? ''), '/');
        $token = trim((string) ($spokeConfig['token'] ?? ''));
        if ($baseUrl === '' || $token === '') {
            throw ValidationException::withMessages([
                'conversion' => 'Site integration is not configured for wallet OPM conversion.',
            ]);
        }

        try {
            $request = Http::baseUrl($baseUrl)
                ->acceptJson()
                ->withToken($token)
                ->connectTimeout((int) ($spokeConfig['connect_timeout_seconds'] ?? 3))
                ->timeout((int) ($spokeConfig['timeout_seconds'] ?? 12));

            $host = trim((string) ($spokeConfig['host'] ?? ''));
            if ($host !== '') {
                $request = $request->withHeaders(['Host' => $host]);
            }

            $response = $request->post('/api/integrations/v1/'.$path, $body);
        } catch (ConnectionException $exception) {
            throw ValidationException::withMessages([
                'conversion' => 'Site integration unreachable: '.$exception->getMessage(),
            ]);
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            $payload = ['message' => $response->body()];
        }

        if ($response->failed()) {
            $message = is_string($payload['message'] ?? null) ? $payload['message'] : 'Site adapter rejected the request.';

            throw ValidationException::withMessages([
                'conversion' => $message,
            ]);
        }

        return $payload;
    }
}
