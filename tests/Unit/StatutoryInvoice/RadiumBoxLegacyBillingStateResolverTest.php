<?php

namespace Tests\Unit\StatutoryInvoice;

use App\Services\StatutoryInvoice\RadiumBoxLegacyBillingStateResolver;
use Tests\TestCase;

class RadiumBoxLegacyBillingStateResolverTest extends TestCase
{
    public function test_legacy_dadra_and_nagar_haveli_maps_to_merged_ut(): void
    {
        $this->assertSame(
            'Dadra and Nagar Haveli and Daman and Diu',
            RadiumBoxLegacyBillingStateResolver::resolve('Dadra and Nagar Haveli'),
        );
    }

    public function test_recognised_state_is_unchanged(): void
    {
        $this->assertSame('Karnataka', RadiumBoxLegacyBillingStateResolver::resolve('Karnataka'));
    }
}
