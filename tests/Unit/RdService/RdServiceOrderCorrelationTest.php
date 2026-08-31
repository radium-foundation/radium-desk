<?php

namespace Tests\Unit\RdService;

use App\Services\RdService\RdServiceOrderCorrelation;
use Tests\TestCase;

class RdServiceOrderCorrelationTest extends TestCase
{
    public function test_ra_numeric_matches_ra_namespaced_stored_id(): void
    {
        $this->assertTrue(RdServiceOrderCorrelation::matches(
            'RA3506771',
            'RA3506771',
            'RA3506771T6a9522b8',
            ['RA3506771T6a9522b8'],
            3506771,
        ));
    }

    public function test_ra_numeric_matches_via_customer_order_id_without_collapsing_cashfree(): void
    {
        $this->assertTrue(RdServiceOrderCorrelation::matches(
            'RA3506771',
            'RA3506771',
            'RA3506771T6a9522b8',
            ['RA3506771T6a9522b8'],
            3506771,
        ));
    }

    public function test_ra_t_suffix_matches_exact_provider_id(): void
    {
        $this->assertTrue(RdServiceOrderCorrelation::matches(
            'RA3506771T6a9522b8',
            'RA3506771',
            'RA3506771T6a9522b8',
            ['RA3506771T6a9522b8'],
            3506771,
        ));
    }

    public function test_historical_rd_numeric_matches_exact_stored_id(): void
    {
        $this->assertTrue(RdServiceOrderCorrelation::matches(
            'RD3506000',
            null,
            'RD3506000',
            ['RD3506000'],
            3506000,
        ));
    }

    public function test_historical_rd_t_suffix_matches_exact_provider_id(): void
    {
        $this->assertTrue(RdServiceOrderCorrelation::matches(
            'RD3506770T6a9522b8',
            null,
            'RD3506770T6a9522b8',
            ['RD3506770T6a9522b8'],
            3506770,
        ));
    }

    public function test_ra_numeric_does_not_resolve_historical_rd_numeric(): void
    {
        $this->assertFalse(RdServiceOrderCorrelation::matches(
            'RA3506771',
            null,
            'RD3506771',
            ['RD3506771'],
            3506771,
        ));
    }

    public function test_ra_numeric_does_not_resolve_historical_rd_t_suffix(): void
    {
        $this->assertFalse(RdServiceOrderCorrelation::matches(
            'RA3506771',
            null,
            'RD3506771T6a9522b8',
            ['RD3506771T6a9522b8'],
            3506771,
        ));
    }

    public function test_ra_numeric_does_not_resolve_numeric_id_when_stored_id_is_rd(): void
    {
        $this->assertFalse(RdServiceOrderCorrelation::matches(
            'RA3506771',
            null,
            null,
            ['RD3506771'],
            3506771,
        ));
    }

    public function test_ra_numeric_may_resolve_numeric_id_only_when_stored_id_is_ra(): void
    {
        $this->assertTrue(RdServiceOrderCorrelation::matches(
            'RA3506771',
            null,
            null,
            ['RA3506771T6a9522b8'],
            3506771,
        ));

        $this->assertFalse(RdServiceOrderCorrelation::matches(
            'RA3506771',
            null,
            null,
            [],
            3506771,
        ));
    }

    public function test_ra_t_suffix_does_not_match_numeric_customer_id_only(): void
    {
        $this->assertFalse(RdServiceOrderCorrelation::matches(
            'RA3506771T6a9522b8',
            'RA3506771',
            'RA3506771',
            ['RA3506771'],
            3506771,
        ));
    }

    public function test_unknown_ra_with_empty_identifiers_does_not_match(): void
    {
        $this->assertFalse(RdServiceOrderCorrelation::matches(
            'RA3506771',
            null,
            null,
            [],
            null,
        ));
    }

    public function test_historical_rd_with_empty_identifiers_still_allowed(): void
    {
        $this->assertTrue(RdServiceOrderCorrelation::matches(
            'RD3506000',
            null,
            null,
            [],
            null,
        ));
    }

    public function test_matching_cashfree_id_does_not_override_conflicting_stored_provider_id(): void
    {
        $this->assertFalse(RdServiceOrderCorrelation::matches(
            'RD3000003',
            null,
            'RD3000003',
            ['RD9999999'],
            9999999,
        ));
    }

    public function test_admin_expected_id_prefers_stored_provider_over_customer_order_id(): void
    {
        $this->assertSame('RA3506771T6a9522b8', RdServiceOrderCorrelation::adminExpectedOrderId(
            'RA3506771',
            'RA3506771',
            'RA3506771T6a9522b8',
            ['RA3506771T6a9522b8'],
        ));
    }
}
