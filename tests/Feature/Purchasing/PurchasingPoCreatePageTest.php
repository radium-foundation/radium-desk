<?php

namespace Tests\Feature\Purchasing;

use App\Models\InventoryBranch;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchasingPoCreatePageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        InventoryBranch::query()->create([
            'code' => 'HQ',
            'name' => 'Head Office',
            'is_active' => true,
        ]);
    }

    public function test_create_page_renders_purchasing_typeahead_layout(): void
    {
        $response = $this->actingAs($this->admin)->get(route('purchasing.purchase-orders.create'));

        $response->assertOk();
        $response->assertSee('Purchase order', false);
        $response->assertSee('id="po-vendor-search"', false);
        $response->assertSee('id="po-product-search"', false);
        $response->assertSee('Ordered qty', false);
        $response->assertSee('assigned automatically on save', false);
        $response->assertSee('Save draft', false);
        $response->assertSee('Send PO', false);
        $response->assertSee('Draft editing is not currently available', false);
        $response->assertDontSee('<select name="vendor_id"', false);
        $response->assertDontSee('non-serial', false);
        $response->assertDontSee('name="serial', false);
    }
}
