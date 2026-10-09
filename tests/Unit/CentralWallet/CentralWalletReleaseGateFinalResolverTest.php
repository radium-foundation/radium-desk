<?php

namespace Tests\Unit\CentralWallet;

use App\CentralWallet\Reliability\CentralWalletReleaseGateFinalResolver;
use Tests\TestCase;

class CentralWalletReleaseGateFinalResolverTest extends TestCase
{
    private CentralWalletReleaseGateFinalResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        config(['central_wallet.release_gate.role' => 'provider']);
        $this->resolver = app(CentralWalletReleaseGateFinalResolver::class);
    }

    public function test_all_pass_sections_yield_final_pass(): void
    {
        $sections = [
            'contract' => ['status' => 'PASS', 'checks' => []],
            'synthetic_wallet' => ['status' => 'PASS', 'checks' => []],
        ];

        $enriched = $this->resolver->enrichSections($sections);
        $final = $this->resolver->resolve($enriched);

        $this->assertSame('PASS', $final['status']);
    }

    public function test_provider_customer_display_warn_is_non_blocking_and_final_passes(): void
    {
        $sections = [
            'synthetic_wallet' => ['status' => 'PASS', 'checks' => []],
            'customer_display' => [
                'status' => 'WARN',
                'blocking' => false,
                'reason' => 'Customer display synthetic is N/A on Desk provider lane (§ F); probe not executed.',
                'checks' => [[
                    'id' => 'customer_display_provider_lane',
                    'result' => 'WARN',
                    'details' => ['message' => 'Customer display synthetic applies to spoke consumers'],
                ]],
            ],
        ];

        $enriched = $this->resolver->enrichSections($sections);
        $this->assertSame('WARN', $enriched['customer_display']['status']);
        $this->assertFalse($enriched['customer_display']['blocking']);
        $this->assertSame('PASS', $this->resolver->resolve($enriched)['status']);
    }

    public function test_account_link_non_blocking_does_not_block_final_pass(): void
    {
        $sections = [
            'synthetic_wallet' => ['status' => 'PASS', 'checks' => []],
            'account_link_variance' => [
                'status' => 'NON-BLOCKING',
                'blocking' => false,
                'checks' => [],
            ],
        ];

        $enriched = $this->resolver->enrichSections($sections);
        $this->assertFalse($enriched['account_link_variance']['blocking']);

        $this->assertSame('PASS', $this->resolver->resolve($enriched)['status']);
    }

    public function test_fail_with_non_blocking_warn_yields_final_fail(): void
    {
        $sections = [
            'overlay_compatibility' => ['status' => 'FAIL', 'checks' => []],
            'customer_display' => [
                'status' => 'WARN',
                'blocking' => false,
                'checks' => [['id' => 'customer_display_provider_lane', 'result' => 'WARN', 'details' => []]],
            ],
        ];

        $this->assertSame('FAIL', $this->resolver->resolve($this->resolver->enrichSections($sections))['status']);
    }

    public function test_blocked_with_non_blocking_warn_yields_final_blocked(): void
    {
        $sections = [
            'synthetic_wallet' => ['status' => 'BLOCKED', 'checks' => []],
            'customer_display' => [
                'status' => 'WARN',
                'blocking' => false,
                'checks' => [['id' => 'customer_display_provider_lane', 'result' => 'WARN', 'details' => []]],
            ],
        ];

        $final = $this->resolver->resolve($this->resolver->enrichSections($sections));
        $this->assertSame('BLOCKED', $final['status']);
    }

    public function test_blocking_warn_yields_final_warn(): void
    {
        $sections = [
            'synthetic_wallet' => ['status' => 'PASS', 'checks' => []],
            'overlay_compatibility' => [
                'status' => 'WARN',
                'checks' => [[
                    'id' => 'overlay_compatibility_baseline',
                    'result' => 'WARN',
                    'details' => ['message' => 'Overlay baseline root not configured'],
                ]],
            ],
        ];

        $enriched = $this->resolver->enrichSections($sections);
        $this->assertTrue($enriched['overlay_compatibility']['blocking']);

        $this->assertSame('WARN', $this->resolver->resolve($enriched)['status']);
    }

    public function test_account_link_pre_deferral_warn_is_non_blocking(): void
    {
        $sections = [
            'synthetic_wallet' => ['status' => 'PASS', 'checks' => []],
            'account_link_variance' => [
                'status' => 'WARN',
                'blocking' => false,
                'checks' => [[
                    'id' => 'account_link_reconciliation_configured',
                    'result' => 'WARN',
                    'details' => ['blocking' => false, 'message' => 'Account-link reconciliation paths not configured'],
                ]],
            ],
        ];

        $enriched = $this->resolver->enrichSections($sections);
        $this->assertFalse($enriched['account_link_variance']['blocking']);
        $this->assertSame('PASS', $this->resolver->resolve($enriched)['status']);
    }

    public function test_multiple_non_blocking_warns_yield_final_pass(): void
    {
        $sections = [
            'synthetic_wallet' => ['status' => 'PASS', 'checks' => []],
            'customer_display' => [
                'status' => 'WARN',
                'blocking' => false,
                'checks' => [['id' => 'customer_display_provider_lane', 'result' => 'WARN', 'details' => []]],
            ],
            'account_link_variance' => ['status' => 'NON-BLOCKING', 'blocking' => false, 'checks' => []],
        ];

        $this->assertSame('PASS', $this->resolver->resolve($this->resolver->enrichSections($sections))['status']);
    }

    public function test_enrich_preserves_warn_visibility_and_classification(): void
    {
        $sections = [
            'customer_display' => [
                'status' => 'WARN',
                'checks' => [[
                    'id' => 'customer_display_provider_lane',
                    'result' => 'WARN',
                    'details' => ['message' => 'Customer display synthetic applies to spoke consumers'],
                ]],
            ],
        ];

        $enriched = $this->resolver->enrichSection('customer_display', $sections['customer_display']);

        $this->assertSame('WARN', $enriched['status']);
        $this->assertFalse($enriched['blocking']);
        $this->assertStringContainsString('provider lane', (string) $enriched['reason']);
    }
}
