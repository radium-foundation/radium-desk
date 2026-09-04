<?php

namespace Tests\Unit\RadiumBoxRead;

use App\Enums\RadiumBoxReadIdentifierType;
use PHPUnit\Framework\TestCase;

class RadiumBoxReadIdentifierTypeTest extends TestCase
{
    public function test_each_type_maps_to_a_distinct_column(): void
    {
        $columns = [];
        foreach (RadiumBoxReadIdentifierType::cases() as $type) {
            $columns[] = $type->describes();
        }

        $this->assertSame([
            'orders.id',
            'orders.ordercode',
            'orders.rdservice_order_id',
            'order_rdservice.id',
            'order_rdservice.rdorderid',
        ], $columns);
        $this->assertCount(5, array_unique($columns));
    }
}
