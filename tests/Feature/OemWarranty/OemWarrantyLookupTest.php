<?php

namespace Tests\Feature\OemWarranty;

use App\Enums\OemWarrantyCaptureMethod;
use App\Enums\OemWarrantyLookupOutcome;
use App\Enums\OemWarrantyProvider;
use App\Models\OemWarrantyLookupSession;
use App\Models\Order;
use App\Models\User;
use App\Services\OemWarranty\OemWarrantyProviderResolver;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OemWarrantyLookupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['oem_warranty.extension_dom_enabled' => true]);
    }

    public function test_provider_resolver_requires_selection_when_metadata_ambiguous(): void
    {
        $order = Order::query()->create([
            'order_id' => 'RD-OEM-001',
            'serial_number' => '7788001',
            'device_model' => 'MFS110 and MSO1300 bundle',
            'product_name' => 'Mantra Morpho kit',
            'status' => 'active',
        ]);

        $resolution = app(OemWarrantyProviderResolver::class)->resolve($order->load('deviceModel'));

        $this->assertTrue($resolution['requires_selection']);
        $this->assertNull($resolution['suggested']);
    }

    public function test_start_session_requires_serial_number(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-OEM-002',
            'serial_number' => null,
            'device_model' => 'MFS110',
            'product_name' => 'Scanner',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->postJson(route('orders.oem-warranty.sessions.start', $order), [
                'provider' => OemWarrantyProvider::Mantratec->value,
            ])
            ->assertForbidden();
    }

    public function test_handoff_draft_rejects_cross_order_serial(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = $this->makeOrderWithSerial('7788001');

        $start = $this->actingAs($user)
            ->postJson(route('orders.oem-warranty.sessions.start', $order), [
                'provider' => OemWarrantyProvider::Mantratec->value,
            ])
            ->assertOk()
            ->json();

        $this->postJson(route('api.v1.oem-warranty.sessions.draft'), [
            'handoff_token' => $start['handoff_token'],
            'capture_method' => OemWarrantyCaptureMethod::ExtensionDom->value,
            'outcome' => OemWarrantyLookupOutcome::Found->value,
            'rows' => [
                [
                    'serial_number' => '9999999',
                    'warranty_expiry_date' => '2030-01-01',
                ],
            ],
        ])->assertUnprocessable();
    }

    public function test_confirm_persists_mantratec_check_and_audit(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-OEM-004',
            'serial_number' => '7788001',
            'device_model' => 'MFS110',
            'product_name' => 'Scanner',
            'status' => 'active',
        ]);

        $start = $this->actingAs($user)
            ->postJson(route('orders.oem-warranty.sessions.start', $order), [
                'provider' => OemWarrantyProvider::Mantratec->value,
            ])
            ->assertOk()
            ->json();

        $this->postJson(route('api.v1.oem-warranty.sessions.draft'), [
            'handoff_token' => $start['handoff_token'],
            'capture_method' => OemWarrantyCaptureMethod::ExtensionDom->value,
            'outcome' => OemWarrantyLookupOutcome::Found->value,
            'rows' => [
                [
                    'serial_number' => '7788001',
                    'warranty_expiry_date' => '2031-06-15',
                    'rd_expiry_date' => '2032-01-01',
                    'model' => 'MFS110',
                ],
            ],
        ])->assertOk();

        $session = OemWarrantyLookupSession::query()->where('public_id', $start['session']['public_id'])->firstOrFail();

        $this->actingAs($user)
            ->postJson(route('orders.oem-warranty.sessions.confirm', [$order, $session]))
            ->assertOk();

        $this->assertDatabaseHas('oem_warranty_checks', [
            'order_id' => $order->id,
            'provider' => OemWarrantyProvider::Mantratec->value,
            'outcome' => OemWarrantyLookupOutcome::Found->value,
            'capture_method' => OemWarrantyCaptureMethod::ExtensionDom->value,
            'mantratec_model' => 'MFS110',
            'is_current' => 1,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'oem_warranty.check.confirmed',
            'auditable_type' => $order->getMorphClass(),
            'auditable_id' => $order->id,
        ]);
    }

    private function makeOrderWithSerial(string $serial): Order
    {
        return Order::query()->create([
            'order_id' => 'RD-OEM-'.substr(md5($serial.microtime()), 0, 6),
            'serial_number' => $serial,
            'device_model' => 'MFS110',
            'product_name' => 'Scanner',
            'status' => 'active',
        ]);
    }

    public function test_draft_token_is_single_use(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = $this->makeOrderWithSerial('7788001');

        $start = $this->actingAs($user)
            ->postJson(route('orders.oem-warranty.sessions.start', $order), [
                'provider' => OemWarrantyProvider::Morpho->value,
            ])
            ->assertOk()
            ->json();

        $payload = [
            'handoff_token' => $start['handoff_token'],
            'capture_method' => OemWarrantyCaptureMethod::ExtensionDom->value,
            'outcome' => OemWarrantyLookupOutcome::NotFound->value,
            'rows' => [['serial_number' => '7788001', 'no_record' => true]],
        ];

        $this->postJson(route('api.v1.oem-warranty.sessions.draft'), $payload)->assertOk();
        $this->postJson(route('api.v1.oem-warranty.sessions.draft'), $payload)->assertUnprocessable();
    }
}
