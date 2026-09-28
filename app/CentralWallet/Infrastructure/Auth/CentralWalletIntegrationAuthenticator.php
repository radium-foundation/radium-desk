<?php

namespace App\CentralWallet\Infrastructure\Auth;

use Illuminate\Http\Request;

final class CentralWalletIntegrationAuthenticator
{
    public const ERROR_UNAUTHORIZED = 'Central Wallet integration authentication failed.';

    public function authenticate(Request $request): bool
    {
        $configuredToken = config('central_wallet.integration_token');
        if (! is_string($configuredToken) || trim($configuredToken) === '') {
            return false;
        }

        $provided = $this->extractBearerToken($request);
        if ($provided === null) {
            return false;
        }

        return hash_equals(trim($configuredToken), $provided);
    }

    public function callerId(Request $request): string
    {
        $siteCode = trim((string) $request->header('X-Site-Code', ''));
        if ($siteCode !== '') {
            return $siteCode;
        }

        return 'central_wallet_service';
    }

    private function extractBearerToken(Request $request): ?string
    {
        $authorization = trim((string) $request->header('Authorization', ''));
        if ($authorization === '') {
            return null;
        }

        if (! str_starts_with(strtolower($authorization), 'bearer ')) {
            return null;
        }

        $token = trim(substr($authorization, 7));

        return $token === '' ? null : $token;
    }
}
