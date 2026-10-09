<?php

namespace App\CentralWallet\Reliability;

use Illuminate\Support\Facades\Http;

/**
 * Read-only synthetic wallet visibility probe. No wallet mutation.
 */
final class CentralWalletSyntheticWalletProbe
{
    public function __construct(
        private readonly CentralWalletContractProbe $contractProbe,
        private readonly WalletVisibilityContractValidator $validator,
    ) {}

    /**
     * @param  array{configured: bool, base_url: string, token: string, site_code: string, local_user_id: string, email: string}  $config
     * @return array{status: string, checks: list<array<string, mixed>>}
     */
    public function probe(array $config): array
    {
        if (! ($config['configured'] ?? false)) {
            return [
                'status' => 'BLOCKED',
                'checks' => [[
                    'id' => 'fixture_configured',
                    'result' => 'BLOCKED',
                    'details' => ['message' => 'SYNTHETIC FIXTURE NOT CONFIGURED'],
                ]],
            ];
        }

        $checks = ($this->contractProbe->probeDeskProvider(
            baseUrl: (string) $config['base_url'],
            integrationToken: (string) $config['token'],
            siteCode: (string) $config['site_code'],
            localUserId: (string) $config['local_user_id'],
            email: (string) $config['email'],
        ))['checks'];

        $checks[] = $this->probeAuthorizedFixture($config);

        return [
            'status' => $this->aggregate($checks),
            'checks' => $checks,
        ];
    }

    /**
     * @param  array{base_url: string, token: string, site_code: string, local_user_id: string, email: string}  $config
     * @return array<string, mixed>
     */
    private function probeAuthorizedFixture(array $config): array
    {
        $url = rtrim((string) $config['base_url'], '/').'/api/central-wallet/v1/wallet-visibility';

        $response = Http::acceptJson()
            ->withToken((string) $config['token'])
            ->withHeaders(['X-Site-Code' => (string) $config['site_code']])
            ->get($url, [
                'site_code' => (string) $config['site_code'],
                'local_user_id' => (string) $config['local_user_id'],
                'email' => (string) $config['email'],
                'email_verified' => 'true',
            ]);

        if ($response->status() !== 200) {
            return [
                'id' => 'authorized_fixture_response',
                'result' => in_array($response->status(), [404, 409], true) ? 'PASS' : 'FAIL',
                'details' => [
                    'http_status' => $response->status(),
                    'note' => '404/409 acceptable for unknown fixture identity; 5xx/401/403 are failures',
                ],
            ];
        }

        $body = $response->json();
        if (! is_array($body)) {
            return [
                'id' => 'authorized_fixture_response',
                'result' => 'FAIL',
                'details' => ['message' => 'malformed JSON response'],
            ];
        }

        try {
            $this->validator->validateSuccessBody($body);
        } catch (\Throwable $exception) {
            return [
                'id' => 'authorized_fixture_response',
                'result' => 'FAIL',
                'details' => ['message' => $exception->getMessage()],
            ];
        }

        $mapsToZeroIncorrectly = $response->status() >= 500
            || (($body['balance_status'] ?? '') === 'verification_required' && ($body['wallet_balance'] ?? null) === null);

        return [
            'id' => 'authorized_fixture_response',
            'result' => $mapsToZeroIncorrectly ? 'FAIL' : 'PASS',
            'details' => [
                'http_status' => 200,
                'balance_status' => $body['balance_status'] ?? null,
                'verification_required' => $body['verification_required'] ?? null,
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $checks
     */
    private function aggregate(array $checks): string
    {
        foreach ($checks as $check) {
            if (($check['result'] ?? '') === 'BLOCKED') {
                return 'BLOCKED';
            }
        }

        foreach ($checks as $check) {
            if (($check['result'] ?? '') === 'FAIL') {
                return 'FAIL';
            }
        }

        return 'PASS';
    }
}
