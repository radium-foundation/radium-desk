<?php

namespace Tests\Unit\HardwareFulfilment;

use App\Models\InventoryBranch;
use App\Services\HardwareFulfilment\HardwarePickupResolver;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class HardwarePickupResolverTest extends TestCase
{
    public function test_owner_confirmed_nicknames_follow_physical_stock_branch(): void
    {
        config([
            'shipping.pickup_locations.delhi' => 'RADDELHI',
            'shipping.pickup_locations.mumbai' => 'RADIUMUM',
        ]);

        $resolver = app(HardwarePickupResolver::class);

        $delhi = new InventoryBranch(['code' => 'DELHI-RETAIL', 'is_active' => true]);
        $mumbai = new InventoryBranch(['code' => 'MUMBAI', 'is_active' => true]);

        $this->assertSame('RADDELHI', $resolver->requireForBranch($delhi));
        $this->assertSame('RADIUMUM', $resolver->requireForBranch($mumbai));
    }

    public function test_empty_nicknames_fail_closed_without_customer_state_fallback(): void
    {
        config([
            'shipping.pickup_locations.delhi' => '',
            'shipping.pickup_locations.mumbai' => '',
        ]);

        $this->expectException(ValidationException::class);

        app(HardwarePickupResolver::class)->requireForBranch(
            new InventoryBranch(['code' => 'DELHI-RETAIL', 'is_active' => true]),
        );
    }
}
