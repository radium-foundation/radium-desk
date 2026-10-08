<?php

namespace App\CentralWallet\Reliability;

use Illuminate\Support\Facades\Http;

/**
 * Optional remote contract probe for UAT/staging/future production synthetic checks.
 * Does not mutate financial state.
 */
final class CentralWalletContractProbe
{
    public function __construct(
        private readonly CentralWalletContractCatalog $catalog,
        private readonly WalletVisibilityContractValidator $validator,
    ) {}

    /**
     * @return array{status: string, checks: list<array<string, mixed>>}
     */
    public function probeDeskProvider(
        string $baseUrl,
        string $integrationToken,
        string $siteCode,
        string $localUserId,
        string $email,
    ): array {
        $checks = [];
        $url = rtrim($baseUrl, '/').'/api/central-wallet/v1/wallet-visibility';

        $unauth = Http::acceptJson()->get($url, [
            'site_code' => $siteCode,
            'local_user_id' => $localUserId,
            'email' => $email,
        ]);
        $checks[] = [
            'id' => 'unauthenticated',
            'result' => $unauth->status() === 401 ? 'PASS' : 'FAIL',
            'details' => ['http_status' => $unauth->status()],
        ];

        $mismatch = Http::acceptJson()
            ->withToken($integrationToken)
            ->withHeaders(['X-Site-Code' => $siteCode])
            ->get($url, [
                'site_code' => 'radiumbox.com',
                'local_user_id' => $localUserId,
                'email' => $email,
            ]);
        $checks[] = [
            'id' => 'site_mismatch',
            'result' => $mismatch->status() === 403 ? 'PASS' : 'FAIL',
            'details' => ['http_status' => $mismatch->status(), 'body' => $mismatch->json()],
        ];

        $missingRouteLikely = $unauth->status() === 404
            || str_contains(strtolower($unauth->body()), 'could not be found');
        $checks[] = [
            'id' => 'route_present',
            'result' => $missingRouteLikely ? 'FAIL' : 'PASS',
            'details' => ['note' => '404 on unauthenticated request indicates missing route when body matches Laravel not-found'],
        ];

        $status = 'PASS';
        foreach ($checks as $check) {
            if (($check['result'] ?? '') === 'FAIL') {
                $status = 'FAIL';
                break;
            }
        }

        return ['status' => $status, 'checks' => $checks];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function validateFixtureSuccess(array $body): void
    {
        $this->validator->validateSuccessBody($body);
    }
}
