<?php

namespace Tests\Unit\CustomerOrderHistory;

use App\Models\Order;
use App\Services\CustomerOrderHistory\SpokeOrderServiceValidityEvaluator;
use Tests\TestCase;

class SpokeOrderServiceValidityEvaluatorTest extends TestCase
{
    private SpokeOrderServiceValidityEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->evaluator = app(SpokeOrderServiceValidityEvaluator::class);
    }

    public function test_expired_when_spoke_amc_status_is_expired(): void
    {
        $order = new Order([
            'order_id' => 'RD1001',
            'amc_status' => 'Expired',
        ]);

        $result = $this->evaluator->evaluate($order, ['warranty' => 'Active']);

        $this->assertSame('expired', $result['status']);
        $this->assertFalse($result['eligible_for_reference']);
    }

    public function test_active_when_spoke_warranty_status_is_active(): void
    {
        $order = new Order([
            'order_id' => 'RD1002',
            'amc_status' => null,
        ]);

        $result = $this->evaluator->evaluate($order, ['warranty' => 'Active']);

        $this->assertSame('active', $result['status']);
        $this->assertTrue($result['eligible_for_reference']);
    }

    public function test_unknown_when_no_spoke_status_fields(): void
    {
        $order = new Order([
            'order_id' => 'RD1003',
        ]);

        $result = $this->evaluator->evaluate($order, []);

        $this->assertSame('unknown', $result['status']);
        $this->assertFalse($result['eligible_for_reference']);
    }
}
