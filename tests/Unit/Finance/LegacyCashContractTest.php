<?php

namespace Tests\Unit\Finance;

use App\Support\Finance\LegacyCashContract;
use PHPUnit\Framework\TestCase;

class LegacyCashContractTest extends TestCase
{
    public function test_idempotency_key_uses_source_table_and_original_id(): void
    {
        $this->assertSame(
            'legacy:radiumbox_prod:expenses:1773',
            LegacyCashContract::idempotencyKey(1773),
        );
    }

    public function test_approved_opening_is_not_the_generic_cash_opening_key(): void
    {
        $this->assertSame('legacy:radiumbox_prod:opening:351014', LegacyCashContract::OPENING_IDEMPOTENCY_KEY);
        $this->assertSame('Opening balance — RadiumBox Admin legacy cash', LegacyCashContract::OPENING_MEMO);
        $this->assertStringNotContainsString('opening:cash:', LegacyCashContract::OPENING_IDEMPOTENCY_KEY);
    }

    public function test_hard_deleted_ids_are_not_fabricated(): void
    {
        $this->assertSame([1, 2, 22, 23, 24, 45, 123, 201], LegacyCashContract::EXCLUDED_LEGACY_IDS);
    }
}
