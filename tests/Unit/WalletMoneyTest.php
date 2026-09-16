<?php

namespace Tests\Unit;

use App\Support\Money\WalletMoney;
use Tests\TestCase;

class WalletMoneyTest extends TestCase
{
    public function test_normalizes_positive_decimal_strings(): void
    {
        $this->assertSame('499.00', WalletMoney::normalize('499'));
        $this->assertSame('499.50', WalletMoney::normalize('499.5'));
        $this->assertNull(WalletMoney::normalize(499.5));
        $this->assertNull(WalletMoney::normalize('-1'));
        $this->assertTrue(WalletMoney::isPositive('0.01'));
        $this->assertFalse(WalletMoney::isPositive('0.00'));
    }
}
