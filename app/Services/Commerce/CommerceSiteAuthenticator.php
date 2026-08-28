<?php

namespace App\Services\Commerce;

use App\Models\Commerce\CommerceSite;
use App\Models\Commerce\CommerceSiteApiKey;
use Illuminate\Http\Request;

class CommerceSiteAuthenticator
{
    public function __construct(
        private readonly CommerceSiteApiKeyHasher $keyHasher,
    ) {}

    /**
     * Resolve an enabled site and valid API key from the request.
     *
     * Returns null when authentication cannot be established. Callers must not
     * distinguish between failure reasons to avoid leaking registry state.
     */
    public function authenticate(Request $request): ?CommerceSite
    {
        $siteIdentifier = trim((string) $request->header(config('commerce.site_id_header', 'X-Site-Id'), ''));
        $plainKey = $this->extractBearerToken($request);

        if ($siteIdentifier === '' || $plainKey === null || $plainKey === '') {
            return null;
        }

        $site = CommerceSite::query()
            ->where('site_id', $siteIdentifier)
            ->where('is_enabled', true)
            ->first();

        if ($site === null) {
            return null;
        }

        $apiKeys = CommerceSiteApiKey::query()
            ->where('commerce_site_id', $site->id)
            ->where('is_active', true)
            ->get();

        foreach ($apiKeys as $apiKey) {
            if ($apiKey->expires_at !== null && $apiKey->expires_at->isPast()) {
                continue;
            }

            if ($this->keyHasher->verify($plainKey, $apiKey->key_hash)) {
                return $site;
            }
        }

        return null;
    }

    private function extractBearerToken(Request $request): ?string
    {
        $authorization = (string) $request->header('Authorization', '');

        if (! str_starts_with($authorization, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($authorization, 7));

        return $token !== '' ? $token : null;
    }
}
