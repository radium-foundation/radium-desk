<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\User;
use App\Services\Interakt\InteraktCustomerMatcher;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InteraktCustomerMatcherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_matching_stored_phones_finds_existing_order(): void
    {
        $user = User::factory()->create();

        Order::query()->create([
            'order_id' => 'RD-MATCH-1',
            'serial_number' => 'SN-MATCH-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $matcher = app(InteraktCustomerMatcher::class);

        $this->assertSame(['9876543210'], $matcher->matchingStoredPhones('+91', '9876543210'));
        $this->assertSame('9876543210', $matcher->resolveStoredPhone('+91', '919876543210'));
    }

    public function test_phone_candidates_include_common_formats(): void
    {
        $matcher = app(InteraktCustomerMatcher::class);

        $candidates = $matcher->phoneCandidates('+91', '9876543210');

        $this->assertContains('9876543210', $candidates);
        $this->assertContains('+919876543210', $candidates);
        $this->assertContains('919876543210', $candidates);
    }
}
