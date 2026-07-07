<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class QuickServiceRequestJsonExceptionScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        config([
            'radiumbox.enabled' => true,
            'radiumbox.base_url' => 'https://admin.radiumbox.com',
        ]);
    }

    public function test_quick_store_ajax_validation_errors_are_json(): void
    {
        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response($this->legacyOrderApiResponse()),
        ]);

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        Order::query()->create([
            'order_id' => 'RD3395988',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->postJson(route('service-requests.quick.store'), [
                'action' => 'legacy_import',
                'legacy_order_id' => 'RD3395988',
                'source' => IncidentSource::Call->value,
                'notes' => 'Duplicate import attempt.',
            ])
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('errors.legacy_order_id.0', 'This order already exists in Radium Desk.');
    }

    public function test_intake_search_validation_still_uses_session_errors_outside_quick_store_json_scope(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->from(route('dashboard'))
            ->post(route('service-requests.intake.search'), [])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrors('search');
    }

    public function test_quick_store_form_post_still_redirects_on_validation(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->from(route('dashboard'))
            ->post(route('service-requests.quick.store'), [
                'action' => 'legacy_import',
                'legacy_order_id' => 'RD3395988',
                'notes' => 'Missing source validation test.',
            ])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrors('source');
    }

    /**
     * @return array<string, mixed>
     */
    private function legacyOrderApiResponse(): array
    {
        $userDetails = json_encode([
            'name' => 'Satyam Test',
            'phone' => '9876543210',
            'email' => 'test@example.com',
        ]);

        return [
            'status' => 200,
            'data' => [
                'order' => [
                    'invoicecode' => 'INV-9988',
                    'orderdate' => '2022-06-15 10:00:00',
                    'userdetails' => $userDetails,
                    'status' => 'Completed',
                ],
                'rd_order' => [
                    'rdorderid' => 'RD3395988',
                    'product_name' => 'MFS 110',
                    'serial_no' => 'SN123456',
                    'userdetails' => $userDetails,
                    'status' => 'Completed',
                ],
            ],
        ];
    }
}
