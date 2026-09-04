<?php

namespace Tests\Unit\Inventory;

use App\Support\Inventory\InventorySerialNumber;
use PHPUnit\Framework\TestCase;

class InventorySerialNumberTest extends TestCase
{
    public function test_parse_list_normalizes_and_deduplicates(): void
    {
        $this->assertSame(
            ['ABC-1', 'DEF-2'],
            InventorySerialNumber::parseList("abc-1\nDEF-2, abc-1 ; "),
        );
    }
}
