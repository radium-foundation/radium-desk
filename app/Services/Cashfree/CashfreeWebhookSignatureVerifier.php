<?php

namespace App\Services\Cashfree;

use Illuminate\Http\Request;

class CashfreeWebhookSignatureVerifier
{
    public const ERROR_INVALID_SIGNATURE = 'Invalid webhook signature';

    public function verify(Request $request): bool
    {
        $signature = $this->headerValue($request, 'x-webhook-signature');
        $timestamp = $this->headerValue($request, 'x-webhook-timestamp');
        $secretKey = (string) config('cashfree.client_secret');

        if ($signature === null || $timestamp === null || $secretKey === '') {
            return false;
        }

        $signedPayload = $timestamp.$request->getContent();
        $expectedSignature = base64_encode(hash_hmac('sha256', $signedPayload, $secretKey, true));

        return hash_equals($expectedSignature, $signature);
    }

    public function hasRequiredHeaders(Request $request): bool
    {
        $signature = $this->headerValue($request, 'x-webhook-signature');
        $timestamp = $this->headerValue($request, 'x-webhook-timestamp');

        return $signature !== null && $timestamp !== null;
    }

    private function headerValue(Request $request, string $name): ?string
    {
        $value = $request->header($name);

        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
