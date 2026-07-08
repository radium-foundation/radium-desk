<?php

namespace App\Services\Bonvoice;

use Illuminate\Http\Request;

class BonvoiceWebhookSignatureVerifier
{
    public const ERROR_INVALID_AUTHORIZATION = 'Invalid BonVoice webhook authorization';

    public function verify(Request $request): bool
    {
        if (! config('bonvoice.verify_signature')) {
            return true;
        }

        $token = $this->extractBearerToken($request);
        $configuredToken = (string) config('bonvoice.webhook_token');

        if ($token === null || $configuredToken === '') {
            return false;
        }

        return hash_equals($configuredToken, $token);
    }

    public function hasRequiredHeaders(Request $request): bool
    {
        if (! config('bonvoice.verify_signature')) {
            return true;
        }

        return $this->headerValue($request, 'Authorization') !== null;
    }

    private function extractBearerToken(Request $request): ?string
    {
        $authorization = $this->headerValue($request, 'Authorization');

        if ($authorization === null || ! str_starts_with($authorization, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($authorization, 7));

        return $token === '' ? null : $token;
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
