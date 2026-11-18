<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Reliability\CentralWalletContractProbe;
use App\CentralWallet\Reliability\CentralWalletReleaseGateProbeHttpTransport;
use App\CentralWallet\Reliability\CentralWalletReleaseGateProbeTransportException;
use App\CentralWallet\Reliability\CentralWalletReleaseGateRunner;
use App\CentralWallet\Reliability\CentralWalletSyntheticWalletProbe;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CentralWalletReleaseGateProbeHttpTransportTest extends TestCase
{
    public function test_loopback_base_url_sends_configured_host_header(): void
    {
        Http::fake(function ($request) {
            $hostHeaders = $request->header('Host');
            $this->assertContains('desk.radiumbox.com', is_array($hostHeaders) ? $hostHeaders : [$hostHeaders]);

            $hasToken = $request->hasHeader('Authorization');
            $siteCode = (string) data_get($request->data(), 'site_code', '');

            if (! $hasToken) {
                return Http::response(['error' => 'unauthenticated'], 401);
            }

            if ($siteCode === 'radiumbox.com') {
                return Http::response(['error' => 'site_mismatch'], 403);
            }

            return Http::response([
                'wallet_balance' => '499.00',
                'available_balance' => '499.00',
                'currency' => 'INR',
                'balance_status' => 'unverified',
                'balance_source' => 'desk_ledger',
                'spendable' => false,
                'verification_required' => true,
                'central_wallet_id' => '00000000-0000-4000-8000-000000000001',
            ], 200);
        });

        $probe = app(CentralWalletSyntheticWalletProbe::class);
        $result = $probe->probe([
            'configured' => true,
            'base_url' => 'http://127.0.0.1',
            'host_header' => 'desk.radiumbox.com',
            'token' => 'token',
            'site_code' => 'rdservice.in',
            'local_user_id' => '3',
            'email' => 'test@example.com',
        ]);

        $this->assertSame('PASS', $result['status']);
    }

    public function test_public_https_url_allows_unset_host_header(): void
    {
        $this->assertNull(
            CentralWalletReleaseGateProbeHttpTransport::resolveHostHeader('https://desk.radiumbox.com', '')
        );
        $this->assertTrue(
            CentralWalletReleaseGateProbeHttpTransport::transportReady('https://desk.radiumbox.com', '')
        );

        Http::fake(function ($request) {
            $hasToken = $request->hasHeader('Authorization');
            $siteCode = (string) data_get($request->data(), 'site_code', '');

            if (! $hasToken) {
                return Http::response(['error' => 'unauthenticated'], 401);
            }

            if ($siteCode === 'radiumbox.com') {
                return Http::response(['error' => 'site_mismatch'], 403);
            }

            return Http::response(['error' => 'unexpected'], 500);
        });

        $result = app(CentralWalletContractProbe::class)->probeDeskProvider(
            baseUrl: 'https://desk.radiumbox.com',
            integrationToken: 'token',
            siteCode: 'rdservice.in',
            localUserId: '3',
            email: 'test@example.com',
        );

        $this->assertSame('PASS', $result['status']);
        Http::assertSentCount(2);
    }

    public function test_loopback_without_host_header_fails_transport_resolution(): void
    {
        $this->expectException(CentralWalletReleaseGateProbeTransportException::class);

        CentralWalletReleaseGateProbeHttpTransport::resolveHostHeader('http://127.0.0.1', '');
    }

    public function test_invalid_host_header_fails_transport_resolution(): void
    {
        $this->expectException(CentralWalletReleaseGateProbeTransportException::class);

        CentralWalletReleaseGateProbeHttpTransport::resolveHostHeader('https://desk.radiumbox.com', 'bad/host');
    }

    public function test_synthetic_probe_blocked_when_loopback_missing_host_header_in_gate_config(): void
    {
        config([
            'central_wallet.release_gate.synthetic_probe.base_url' => 'http://127.0.0.1',
            'central_wallet.release_gate.synthetic_probe.host_header' => '',
            'central_wallet.release_gate.synthetic_probe.integration_token' => 'token',
            'central_wallet.release_gate.synthetic_probe.site_code' => 'rdservice.in',
            'central_wallet.release_gate.synthetic_probe.local_user_id' => '3',
            'central_wallet.release_gate.synthetic_probe.email' => 'test@example.com',
        ]);

        $report = app(CentralWalletReleaseGateRunner::class)->run('pre');

        $this->assertSame('BLOCKED', $report['sections']['synthetic_wallet']['status']);
    }
}
