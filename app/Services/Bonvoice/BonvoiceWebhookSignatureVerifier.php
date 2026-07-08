<?php

namespace App\Services\Bonvoice;

use Illuminate\Http\Request;

class BonvoiceWebhookSignatureVerifier
{
    public const ERROR_INVALID_SIGNATURE = 'Invalid BonVoice webhook signature';

    public function verify(Request $request): bool
    {
        $signature = $this->headerValue($request, 'bonvoice-signature')
            ?? $this->headerValue($request, 'x-bonvoice-signature');
        $secretKey = (string) config('bonvoice.webhook_secret');

        if ($signature === null || $secretKey === '') {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secretKey);

        return hash_equals($expected, $signature);
    }

    public function hasRequiredHeaders(Request $request): bool
    {
        return $this->headerValue($request, 'bonvoice-signature') !== null
            || $this->headerValue($request, 'x-bonvoice-signature') !== null;
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
