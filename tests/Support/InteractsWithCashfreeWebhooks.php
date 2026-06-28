<?php

namespace Tests\Support;

use Illuminate\Testing\TestResponse;

trait InteractsWithCashfreeWebhooks
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $extraHeaders
     */
    protected function postSignedCashfreeWebhook(array $payload, array $extraHeaders = []): TestResponse
    {
        $rawBody = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) (int) (microtime(true) * 1000);
        $secretKey = (string) config('cashfree.client_secret');
        $signature = base64_encode(hash_hmac('sha256', $timestamp.$rawBody, $secretKey, true));

        $headers = array_merge([
            'X-Webhook-Timestamp' => $timestamp,
            'X-Webhook-Signature' => $signature,
            'Content-Type' => 'application/json',
        ], $extraHeaders);

        return $this->call(
            'POST',
            '/api/webhooks/cashfree',
            [],
            [],
            [],
            $this->transformHeaders($headers),
            $rawBody,
        );
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function transformHeaders(array $headers): array
    {
        $transformed = [];

        foreach ($headers as $name => $value) {
            $transformed['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $transformed;
    }
}
