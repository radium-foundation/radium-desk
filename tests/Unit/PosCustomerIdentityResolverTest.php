<?php

namespace Tests\Unit;

use App\Models\InventoryCustomer;
use App\Services\Pos\PosCustomerIdentityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PosCustomerIdentityResolverTest extends TestCase
{
    use RefreshDatabase;

    private PosCustomerIdentityResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = app(PosCustomerIdentityResolver::class);
    }

    public function test_detects_name_and_gstin_conflicts(): void
    {
        $existing = new InventoryCustomer([
            'name' => 'I K Enterprises',
            'phone' => '8847638343',
            'gstin' => '03BPDPK2984N1ZK',
        ]);

        $conflict = $this->resolver->detectConflict($existing, [
            'name' => 'INAAYAT COMMUNICATIONS',
            'phone' => '8847638343',
            'gstin' => '03EDPPS6488J1ZN',
        ]);

        $this->assertNotNull($conflict);
        $this->assertTrue($conflict['name_conflict']);
        $this->assertTrue($conflict['gstin_conflict']);
    }

    public function test_case_insensitive_name_match_is_not_a_conflict(): void
    {
        $existing = new InventoryCustomer([
            'name' => 'I K Enterprises',
            'phone' => '8847638343',
            'gstin' => '03BPDPK2984N1ZK',
        ]);

        $this->assertNull($this->resolver->detectConflict($existing, [
            'name' => 'i k enterprises',
            'gstin' => '03BPDPK2984N1ZK',
        ]));
    }

    public function test_b2c_repeat_sale_without_gstin_is_not_a_gstin_conflict(): void
    {
        $existing = new InventoryCustomer([
            'name' => 'I K Enterprises',
            'phone' => '8847638343',
            'gstin' => '03BPDPK2984N1ZK',
        ]);

        $this->assertNull($this->resolver->detectConflict($existing, [
            'name' => 'I K Enterprises',
            'gstin' => null,
        ]));
    }

    public function test_require_resolution_if_conflict_blocks_without_choice(): void
    {
        $existing = InventoryCustomer::query()->create([
            'name' => 'I K Enterprises',
            'phone' => '8847638343',
            'gstin' => '03BPDPK2984N1ZK',
        ]);

        $this->expectException(ValidationException::class);

        $this->resolver->requireResolutionIfConflict($existing, [
            'name' => 'INAAYAT COMMUNICATIONS',
            'gstin' => '03EDPPS6488J1ZN',
        ], null);
    }
}
