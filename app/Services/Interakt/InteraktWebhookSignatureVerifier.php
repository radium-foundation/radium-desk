<?php

namespace App\Services\Interakt;

use Illuminate\Http\Request;

class InteraktWebhookSignatureVerifier
{
    public const ERROR_INVALID_SIGNATURE = 'Invalid Interakt webhook signature';

    public function verify(Request $request): bool
    {
        $signature = $this->headerValue($request, 'interakt-signature');
        $secretKey = (string) config('interakt.webhook_secret');

        if ($signature === null || $secretKey === '') {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secretKey);

        return hash_equals($expected, $signature);
    }

    public function hasRequiredHeaders(Request $request): bool
    {
        return $this->headerValue($request, 'interakt-signature') !== null;
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
