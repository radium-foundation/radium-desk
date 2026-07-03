<?php

namespace App\Services\Interakt;

class InteraktAuthentication
{
    /**
     * Standard headers for outbound Interakt API requests.
     *
     * Interakt does not use RFC 7617 Basic Authentication. The API key is sent
     * literally after the "Basic " prefix without base64 encoding.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        return [
            'Authorization' => 'Basic '.(string) config('interakt.api_key'),
            'Accept' => 'application/json',
        ];
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
                $redacted[$key] = 'Basic ********';
            }
        }

        return $redacted;
    }
}
