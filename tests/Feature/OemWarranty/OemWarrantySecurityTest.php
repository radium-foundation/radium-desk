<?php

namespace Tests\Feature\OemWarranty;

use App\Enums\OemWarrantyCaptureMethod;
use App\Enums\OemWarrantyLookupOutcome;
use App\Enums\OemWarrantyLookupSessionStatus;
use App\Enums\OemWarrantyProvider;
use App\Models\OemWarrantyLookupSession;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OemWarrantySecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_guest_cannot_start_lookup(): void
    {
        $order = $this->makeOrderWithSerial('7788001');

        $response = $this->postJson(route('orders.oem-warranty.sessions.start', $order), [
            'provider' => OemWarrantyProvider::Mantratec->value,
        ]);

        $this->assertContains($response->status(), [401, 302], 'Guests must not start OEM warranty lookups.');
    }

    public function test_cross_user_confirm_returns_forbidden(): void
    {
        config(['oem_warranty.extension_dom_enabled' => true]);

        $owner = User::factory()->create();
        $owner->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $other = User::factory()->create();
        $other->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = $this->makeOrderWithSerial('7788001');

        $start = $this->actingAs($owner)
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

        $session = OemWarrantyLookupSession::query()
            ->where('public_id', $start['session']['public_id'])
            ->firstOrFail();

        $this->actingAs($other)
            ->postJson(route('orders.oem-warranty.sessions.confirm', [$order, $session]))
            ->assertForbidden();
    }

    public function test_cross_order_session_access_returns_unprocessable(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $orderA = $this->makeOrderWithSerial('7788001');
        $orderB = $this->makeOrderWithSerial('7788002');

        $start = $this->actingAs($user)
            ->postJson(route('orders.oem-warranty.sessions.start', $orderA), [
                'provider' => OemWarrantyProvider::Mantratec->value,
            ])
            ->assertOk()
            ->json();

        $session = OemWarrantyLookupSession::query()
            ->where('public_id', $start['session']['public_id'])
            ->firstOrFail();

        $this->actingAs($user)
            ->postJson(route('orders.oem-warranty.sessions.confirm', [$orderB, $session]))
            ->assertUnprocessable();
    }

    public function test_expired_session_rejects_draft_submission(): void
    {
        config(['oem_warranty.extension_dom_enabled' => true]);

        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = $this->makeOrderWithSerial('7788001');

        $start = $this->actingAs($user)
            ->postJson(route('orders.oem-warranty.sessions.start', $order), [
                'provider' => OemWarrantyProvider::Mantratec->value,
            ])
            ->assertOk()
            ->json();

        OemWarrantyLookupSession::query()
            ->where('public_id', $start['session']['public_id'])
            ->update([
                'expires_at' => now()->subMinute(),
                'status' => OemWarrantyLookupSessionStatus::OpeningPortal->value,
            ]);

        $this->postJson(route('api.v1.oem-warranty.sessions.draft'), [
            'handoff_token' => $start['handoff_token'],
            'capture_method' => OemWarrantyCaptureMethod::ExtensionDom->value,
            'outcome' => OemWarrantyLookupOutcome::Found->value,
            'rows' => [
                [
                    'serial_number' => '7788001',
                    'warranty_expiry_date' => '2031-06-15',
                    'model' => 'MFS110',
                ],
            ],
        ])->assertUnprocessable();
    }

    public function test_replayed_confirm_is_rejected(): void
    {
        config(['oem_warranty.extension_dom_enabled' => true]);

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
                    'serial_number' => '7788001',
                    'warranty_expiry_date' => '2031-06-15',
                    'model' => 'MFS110',
                ],
            ],
        ])->assertOk();

        $session = OemWarrantyLookupSession::query()
            ->where('public_id', $start['session']['public_id'])
            ->firstOrFail();

        $this->actingAs($user)
            ->postJson(route('orders.oem-warranty.sessions.confirm', [$order, $session]))
            ->assertOk();

        $response = $this->actingAs($user)
            ->postJson(route('orders.oem-warranty.sessions.confirm', [$order, $session]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('session');
    }

    public function test_cross_provider_result_submission_is_rejected(): void
    {
        config(['oem_warranty.extension_dom_enabled' => true]);

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
            'provider' => OemWarrantyProvider::Morpho->value,
            'rows' => [
                [
                    'serial_number' => '7788001',
                    'order_number' => 'ORD-1',
                    'whitelisting_date' => '2024-01-01',
                    'expiry_date' => '2025-01-01',
                    'validity_years' => '1',
                ],
            ],
        ])->assertUnprocessable();
    }

    public function test_extension_dom_capture_rejected_by_default(): void
    {
        config(['oem_warranty.extension_dom_enabled' => false]);

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
                    'serial_number' => '7788001',
                    'warranty_expiry_date' => '2031-06-15',
                    'model' => 'MFS110',
                ],
            ],
        ])->assertUnprocessable();

        $this->actingAs($user)
            ->postJson(route('orders.oem-warranty.manual', $order), [
                'provider' => OemWarrantyProvider::Mantratec->value,
                'outcome' => OemWarrantyLookupOutcome::Found->value,
                'rows' => [
                    [
                        'serial_number' => '7788001',
                        'warranty_expiry_date' => '2031-06-15',
                        'model' => 'MFS110',
                    ],
                ],
            ])
            ->assertOk();
    }

    public function test_context_reports_extension_dom_disabled_by_default(): void
    {
        config(['oem_warranty.extension_dom_enabled' => false]);

        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = $this->makeOrderWithSerial('7788001');

        $this->actingAs($user)
            ->getJson(route('orders.oem-warranty.context', $order))
            ->assertOk()
            ->assertJsonPath('extension_dom_enabled', false)
            ->assertJsonPath('capture_attestation', 'Staff-attested portal results; not vendor-authorized automated extraction.');
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
}
