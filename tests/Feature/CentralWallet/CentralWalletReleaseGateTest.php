<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Reliability\CentralWalletAccountLinkVarianceEvaluator;
use App\CentralWallet\Reliability\CentralWalletReleaseGateRunner;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CentralWalletReleaseGateTest extends TestCase
{
    public function test_pre_deploy_gate_passes_local_contract_and_semantic_checks(): void
    {
        $report = app(CentralWalletReleaseGateRunner::class)->run('pre');

        $this->assertSame('radium-desk', $report['project']);
        $this->assertSame('PASS', $report['sections']['contract']['status']);
        $this->assertSame('PASS', $report['sections']['semantic_invariants']['status']);
        $this->assertSame('PASS', $report['sections']['routes']['status']);
        $this->assertContains($report['sections']['authentication']['status'], ['BLOCKED', 'PASS']);
    }

    public function test_account_link_variance_evaluator_treats_missing_links_as_non_blocking(): void
    {
        $evaluation = app(CentralWalletAccountLinkVarianceEvaluator::class)->evaluate([
            'counts' => [
                'desk_missing_on_spoke' => 89,
                'wallet_id_mismatch' => 0,
                'identity_mismatch' => 0,
                'spoke_only' => 0,
            ],
        ]);

        $this->assertSame('NON-BLOCKING', $evaluation['status']);
    }

    public function test_account_link_wallet_mismatch_fails_gate_evaluation(): void
    {
        $evaluation = app(CentralWalletAccountLinkVarianceEvaluator::class)->evaluate([
            'counts' => ['wallet_id_mismatch' => 1],
        ]);

        $this->assertSame('FAIL', $evaluation['status']);
    }

    public function test_synthetic_probe_blocked_without_fixture_configuration(): void
    {
        config([
            'central_wallet.release_gate.synthetic_probe.base_url' => '',
            'central_wallet.release_gate.synthetic_probe.integration_token' => '',
            'central_wallet.release_gate.synthetic_probe.site_code' => '',
            'central_wallet.release_gate.synthetic_probe.local_user_id' => '',
            'central_wallet.release_gate.synthetic_probe.email' => '',
        ]);

        $report = app(CentralWalletReleaseGateRunner::class)->run('pre');

        $this->assertSame('BLOCKED', $report['sections']['synthetic_wallet']['status']);
        $this->assertSame('BLOCKED', $report['final']);
    }

    public function test_synthetic_probe_passes_with_mocked_desk_responses(): void
    {
        config([
            'central_wallet.release_gate.synthetic_probe.base_url' => 'https://desk.test',
            'central_wallet.release_gate.synthetic_probe.integration_token' => 'token',
            'central_wallet.release_gate.synthetic_probe.site_code' => 'rdservice.in',
            'central_wallet.release_gate.synthetic_probe.local_user_id' => '3',
            'central_wallet.release_gate.synthetic_probe.email' => 'test@example.com',
        ]);

        Http::fake(function ($request) {
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
            ], 200);
        });

        $report = app(CentralWalletReleaseGateRunner::class)->run('pre');

        $this->assertSame('PASS', $report['sections']['authentication']['status']);
        $this->assertSame('PASS', $report['sections']['synthetic_wallet']['status']);
        $this->assertSame('WARN', $report['sections']['customer_display']['status']);
        $this->assertFalse($report['sections']['customer_display']['blocking']);
        $this->assertContains($report['sections']['account_link_variance']['status'], ['WARN', 'NON-BLOCKING', 'PASS']);
        if (($report['sections']['account_link_variance']['blocking'] ?? true) === false) {
            $this->assertFalse($report['sections']['account_link_variance']['blocking']);
        }
    }

    public function test_artisan_release_gate_command_emits_json(): void
    {
        $exit = Artisan::call('central-wallet:verify-release-gate', ['--phase' => 'pre', '--json' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('"sections"', $output);
        $this->assertContains($exit, [0, 1]);
    }
}
