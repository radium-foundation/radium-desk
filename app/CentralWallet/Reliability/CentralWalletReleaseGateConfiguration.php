<?php

namespace App\CentralWallet\Reliability;

final class CentralWalletReleaseGateConfiguration
{
    public function role(): string
    {
        return (string) config('central_wallet.release_gate.role', 'provider');
    }

    public function projectKey(): string
    {
        return (string) config('central_wallet.release_gate.project_key', 'radium-desk');
    }

    public function phase(): string
    {
        return (string) config('central_wallet.release_gate.phase', 'pre');
    }

    /**
     * @return array{configured: bool, base_url: string, token: string, site_code: string, local_user_id: string, email: string}
     */
    public function syntheticProbe(): array
    {
        $baseUrl = rtrim(trim((string) config('central_wallet.release_gate.synthetic_probe.base_url', '')), '/');
        $token = trim((string) config('central_wallet.release_gate.synthetic_probe.integration_token', ''));
        $siteCode = trim((string) config('central_wallet.release_gate.synthetic_probe.site_code', ''));
        $localUserId = trim((string) config('central_wallet.release_gate.synthetic_probe.local_user_id', ''));
        $email = trim((string) config('central_wallet.release_gate.synthetic_probe.email', ''));

        return [
            'configured' => $baseUrl !== '' && $token !== '' && $siteCode !== '' && $localUserId !== '' && $email !== '',
            'base_url' => $baseUrl,
            'token' => $token,
            'site_code' => $siteCode,
            'local_user_id' => $localUserId,
            'email' => $email,
        ];
    }

    public function accountLinkReconciliationEnabled(): bool
    {
        return filter_var(
            config('central_wallet.release_gate.account_link_reconciliation.enabled', false),
            FILTER_VALIDATE_BOOLEAN,
        );
    }

    public function deskEnvPath(): ?string
    {
        $path = trim((string) config('central_wallet.release_gate.account_link_reconciliation.desk_env_path', ''));

        return $path !== '' ? $path : null;
    }

    public function spokeEnvPath(): ?string
    {
        $path = trim((string) config('central_wallet.release_gate.account_link_reconciliation.spoke_env_path', ''));

        return $path !== '' ? $path : null;
    }
}
