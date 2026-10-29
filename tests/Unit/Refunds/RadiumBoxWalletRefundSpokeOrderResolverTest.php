<?php

namespace Tests\Unit\Refunds;

use App\Support\Refunds\RadiumBoxWalletRefundSpokeOrderResolver;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RadiumBoxWalletRefundSpokeOrderResolverTest extends TestCase
{
    public function test_resolves_desk_rb_order_to_spoke_order_code_from_lookup(): void
    {
        config([
            'order_lookup.spokes.radiumbox_com.enabled' => true,
            'order_lookup.spokes.radiumbox_com.base_url' => 'https://radiumbox.test',
            'order_lookup.spokes.radiumbox_com.token' => 'lookup-token',
            'order_lookup.spokes.radiumbox_com.accepts' => ['rd', 'rde', 'rb', 'rbp', 'rbx'],
        ]);

        Http::fake([
            'https://radiumbox.test/api/integrations/v1/rd-orders/RB1010' => Http::response([
                'status' => 200,
                'data' => [
                    'correlation' => [
                        'rdorderid' => 'RB1010',
                        'ordercode' => 'RD66240',
                        'orders_id' => 66240,
                    ],
                ],
            ], 200),
        ]);

        $resolved = app(RadiumBoxWalletRefundSpokeOrderResolver::class)->resolveWalletOrderId('RB1010');

        $this->assertSame('RD66240', $resolved);
    }
}
