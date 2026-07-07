<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\NewContactIntent;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\CustomerVerificationService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerIntakeVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        $dayAdmin = User::factory()->create(['email' => 'day-admin@test.com']);
        $dayAdmin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $nightAdmin = User::factory()->create(['email' => 'night-admin@test.com']);
        $nightAdmin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        app(\App\Services\SettingService::class)->setMany([
            'assignment.timezone' => config('app.timezone'),
            'assignment.day_shift_start' => '09:00',
            'assignment.day_shift_end' => '18:30',
            'assignment.day_shift_admin_user_id' => (string) $dayAdmin->id,
            'assignment.night_shift_admin_user_id' => (string) $nightAdmin->id,
            'assignment.fallback_admin_1_user_id' => '',
            'assignment.fallback_admin_2_user_id' => '',
        ]);
    }

    public function test_cashfree_customer_can_assign_service_reference_without_legacy_verification(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'CF-ORDER-001',
            'serial_number' => 'SN-CF-001',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'cashfree_payment_id' => 'cf_pay_001',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->post(route('orders.transaction.store', $order), [
                'transaction_id' => 'TXN-CF-001',
            ])
            ->assertRedirect(route('orders.show', $order));

        $this->assertSame('TXN-CF-001', $order->fresh()->transaction_id);
    }

    public function test_legacy_customer_requires_verification_before_service_reference(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-LEGACY-001',
            'serial_number' => 'SN-LEGACY-001',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->post(route('orders.transaction.store', $order), [
                'transaction_id' => 'TXN-LEGACY-001',
            ])
            ->assertSessionHasErrors('transaction_id');

        $this->assertNull($order->fresh()->transaction_id);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'transaction.assignment_blocked',
            'auditable_type' => $order->getMorphClass(),
            'auditable_id' => $order->id,
        ]);
    }

    public function test_legacy_customer_can_complete_service_after_verification_confirmation(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-LEGACY-002',
            'serial_number' => 'SN-LEGACY-002',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->postJson(route('orders.legacy-verification.store', $order), [
                'confirmed' => true,
            ])
            ->assertOk();

        $this->actingAs($admin)
            ->post(route('orders.transaction.store', $order), [
                'transaction_id' => 'TXN-LEGACY-002',
            ])
            ->assertRedirect(route('orders.show', $order));

        $this->assertSame('TXN-LEGACY-002', $order->fresh()->transaction_id);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'legacy.verification_completed',
            'auditable_type' => $order->getMorphClass(),
            'auditable_id' => $order->id,
        ]);
    }

    public function test_unknown_customer_can_create_general_support_inquiry(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $response = $this->actingAs($agent)->post(route('service-requests.quick.store'), [
            'action' => 'new_contact',
            'intent' => NewContactIntent::GeneralSupport->value,
            'phone' => '9876543210',
            'source' => IncidentSource::Call->value,
            'notes' => 'Caller asked about office hours.',
        ]);

        $incident = Incident::query()->first();
        $this->assertNotNull($incident);

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHas('status', 'service-case-created');

        $this->assertTrue(Order::isInquiryOrderId($incident->order->order_id));
        $this->assertSame('General Support', $incident->category);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'intake.new_contact_created',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
        ]);
    }

    public function test_buy_device_intent_does_not_create_fake_order_identity(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)->post(route('service-requests.quick.store'), [
            'action' => 'new_contact',
            'intent' => NewContactIntent::BuyDevice->value,
            'phone' => '9123456789',
            'source' => IncidentSource::Call->value,
            'notes' => 'Interested in purchasing a new device.',
        ])->assertRedirect();

        $order = Order::query()->first();
        $this->assertNotNull($order);
        $this->assertTrue(Order::isInquiryOrderId($order->order_id));
        $this->assertFalse(str_starts_with($order->order_id, 'RD'));
        $this->assertNull($order->serial_number);
        $this->assertNull($order->cashfree_payment_id);

        $incident = Incident::query()->first();
        $this->assertSame('Sales Lead', $incident->category);
    }

    public function test_unverified_new_contact_cannot_add_service_reference(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'INQ-SC00001',
            'customer_phone' => '9000000001',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $verificationService = app(CustomerVerificationService::class);
        $this->assertFalse($verificationService->canCompleteService($order));

        $this->actingAs($admin)
            ->post(route('orders.transaction.store', $order), [
                'transaction_id' => 'TXN-UNVERIFIED',
            ])
            ->assertSessionHasErrors('transaction_id');
    }

    public function test_shared_service_reference_assignment_is_allowed(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $firstOrder = Order::query()->create([
            'order_id' => 'RD-DUP-001',
            'serial_number' => 'SN-DUP-001',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'cashfree_payment_id' => 'cf_dup_001',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $secondOrder = Order::query()->create([
            'order_id' => 'RD-DUP-002',
            'serial_number' => 'SN-DUP-002',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'cashfree_payment_id' => 'cf_dup_002',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->post(route('orders.transaction.store', $firstOrder), [
                'transaction_id' => 'TXN-DUP-SHARED',
            ])
            ->assertRedirect();

        $this->actingAs($admin)
            ->post(route('orders.transaction.store', $secondOrder), [
                'transaction_id' => 'TXN-DUP-SHARED',
            ])
            ->assertRedirect(route('orders.show', $secondOrder));

        $this->assertSame('TXN-DUP-SHARED', $secondOrder->fresh()->transaction_id);
    }

    public function test_intake_search_classifies_cashfree_and_legacy_matches(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $cashfreeOrder = Order::query()->create([
            'order_id' => 'CF-SEARCH-001',
            'cashfree_payment_id' => 'cf_search_001',
            'customer_phone' => '9111111111',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $legacyOrder = Order::query()->create([
            'order_id' => 'RD-SEARCH-001',
            'customer_phone' => '9222222222',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->postJson(route('service-requests.intake.search'), [
                'phone' => '9111111111',
            ])
            ->assertOk()
            ->assertJsonPath('classification', 'cashfree_verified')
            ->assertJsonPath('matches.0.id', $cashfreeOrder->id);

        $this->actingAs($agent)
            ->postJson(route('service-requests.intake.search'), [
                'order_id' => 'RD-SEARCH-001',
            ])
            ->assertOk()
            ->assertJsonPath('classification', 'legacy')
            ->assertJsonPath('matches.0.id', $legacyOrder->id);

        $this->actingAs($agent)
            ->postJson(route('service-requests.intake.search'), [
                'phone' => '9999999999',
            ])
            ->assertOk()
            ->assertJsonPath('classification', 'new_contact')
            ->assertJsonPath('matches', []);
    }
}
