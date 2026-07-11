<?php

namespace Tests\Feature;

use App\Data\CustomerCorrectionData;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\TimelineEventType;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\CustomerCorrectionService;
use App\Services\IncidentReferenceService;
use App\Services\OrderIdentityProtectionService;
use App\Services\RadiumBox\RadiumBoxService;
use App\Services\Timeline\Customer360TimelineService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrderIdentityProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'radiumbox.enabled' => true,
            'radiumbox.base_url' => 'https://admin.radiumbox.com',
            'radiumbox.timeout_seconds' => 5,
            'radiumbox.connect_timeout_seconds' => 3,
        ]);
    }

    #[Test]
    public function customer_correction_locks_corrected_fields(): void
    {
        $actor = $this->adminUser();

        $order = Order::query()->create([
            'order_id' => 'RD-LOCK-CORRECT',
            'customer_name' => 'Old Name',
            'customer_phone' => '9876543210',
            'customer_email' => 'old@example.com',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        app(CustomerCorrectionService::class)->apply(
            $order,
            new CustomerCorrectionData(
                customerName: 'New Name',
                customerPhone: '9123456789',
                customerEmail: 'old@example.com',
                reason: 'Customer confirmed updated contact details.',
            ),
            $actor,
        );

        $order->refresh();

        $this->assertSame('New Name', $order->customer_name);
        $this->assertSame('9123456789', $order->customer_phone);
        $this->assertTrue($order->isCustomerNameLocked());
        $this->assertTrue($order->isCustomerPhoneLocked());
        $this->assertFalse($order->isCustomerEmailLocked());
        $this->assertSame($actor->id, $order->customer_name_locked_by);
        $this->assertSame($actor->id, $order->customer_phone_locked_by);
        $this->assertNotNull($order->customer_name_locked_at);
        $this->assertNotNull($order->customer_phone_locked_at);
    }

    #[Test]
    public function radiumbox_cannot_overwrite_locked_customer_identity_values(): void
    {
        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response([
                'status' => 200,
                'data' => [
                    'rd_order' => [
                        'serial_no' => 'RB-SERIAL-1',
                        'product_name' => 'MFS 110',
                        'customer_name' => 'RadiumBox Name',
                        'customer_phone' => '9000000001',
                        'customer_email' => 'radiumbox@example.com',
                    ],
                ],
            ]),
        ]);

        $actor = $this->adminUser();

        $order = Order::query()->create([
            'order_id' => 'RD-LOCK-RB',
            'customer_name' => 'Verified Name',
            'customer_phone' => '9876543210',
            'customer_email' => 'verified@example.com',
            'customer_name_locked_at' => now(),
            'customer_name_locked_by' => $actor->id,
            'customer_phone_locked_at' => now(),
            'customer_phone_locked_by' => $actor->id,
            'customer_email_locked_at' => now(),
            'customer_email_locked_by' => $actor->id,
            'serial_number' => 'LOCKED-SERIAL',
            'serial_entered_at' => now(),
            'serial_entered_by_user_id' => $actor->id,
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        app(RadiumBoxService::class)->enrichOrderFromBackgroundSync($order);

        $order->refresh();

        $this->assertSame('Verified Name', $order->customer_name);
        $this->assertSame('9876543210', $order->customer_phone);
        $this->assertSame('verified@example.com', $order->customer_email);
        $this->assertSame('LOCKED-SERIAL', $order->serial_number);
    }

    #[Test]
    public function unlocked_customer_fields_continue_syncing_from_radiumbox(): void
    {
        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response([
                'status' => 200,
                'data' => [
                    'rd_order' => [
                        'serial_no' => 'RB-SERIAL-2',
                        'product_name' => 'MFS 110',
                        'customer_name' => 'Synced Name',
                        'customer_phone' => '9000000002',
                        'customer_email' => 'synced@example.com',
                    ],
                ],
            ]),
        ]);

        $actor = $this->adminUser();

        $order = Order::query()->create([
            'order_id' => 'RD-UNLOCKED-RB',
            'customer_name' => 'Placeholder Name',
            'customer_phone' => null,
            'customer_email' => null,
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        app(RadiumBoxService::class)->enrichOrderFromBackgroundSync($order);

        $order->refresh();

        $this->assertSame('Synced Name', $order->customer_name);
        $this->assertSame('9000000002', $order->customer_phone);
        $this->assertSame('synced@example.com', $order->customer_email);
        $this->assertSame('RB-SERIAL-2', $order->serial_number);
    }

    #[Test]
    public function serial_correction_remains_locked_after_correction(): void
    {
        $admin = $this->adminUser();
        [, $incident] = $this->createIncidentWithSerial($admin, '7881953');

        $this->withHeaders(['Sec-Fetch-Site' => 'same-origin'])
            ->actingAs($admin)
            ->patchJson(route('incidents.workspace.correct-serial-number', $incident), [
                'serial_number' => '7881954',
                'reason' => 'Customer confirmed the correct serial.',
                'workspace_context' => 'customer',
            ])
            ->assertOk();

        $order = $incident->order->fresh();

        $this->assertSame('7881954', $order->serial_number);
        $this->assertTrue($order->isSerialLocked());
        $this->assertNotNull($order->serial_entered_at);
        $this->assertSame($admin->id, $order->serial_entered_by_user_id);

        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response([
                'status' => 200,
                'data' => [
                    'rd_order' => [
                        'serial_no' => '9999999',
                        'product_name' => 'MFS 110',
                    ],
                ],
            ]),
        ]);

        app(RadiumBoxService::class)->enrichOrderFromBackgroundSync($order->fresh());

        $this->assertSame('7881954', $order->fresh()->serial_number);
    }

    #[Test]
    public function customer_correction_audit_log_is_unchanged(): void
    {
        $actor = $this->adminUser();

        $order = Order::query()->create([
            'order_id' => 'RD-LOCK-AUDIT',
            'customer_name' => 'Old Name',
            'customer_phone' => '9876543210',
            'customer_email' => 'old@example.com',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        app(CustomerCorrectionService::class)->apply(
            $order,
            new CustomerCorrectionData(
                customerName: 'New Name',
                customerPhone: '9876543210',
                customerEmail: 'old@example.com',
                reason: 'Customer confirmed the correct spelling.',
            ),
            $actor,
        );

        $auditLog = AuditLog::query()
            ->where('event', 'customer.details.corrected')
            ->where('auditable_id', $order->id)
            ->first();

        $this->assertNotNull($auditLog);
        $this->assertSame('Old Name', $auditLog->old_values['customer_name']);
        $this->assertSame('New Name', $auditLog->new_values['customer_name']);
        $this->assertArrayNotHasKey('customer_name_locked_at', $auditLog->new_values);
        $this->assertArrayNotHasKey('customer_name_locked_by', $auditLog->new_values);
    }

    #[Test]
    public function timeline_includes_initial_protection_event_only_once_per_field(): void
    {
        $actor = $this->adminUser();
        $order = Order::query()->create([
            'order_id' => 'RD-LOCK-TIMELINE',
            'customer_name' => 'Old Name',
            'customer_phone' => '9876543210',
            'customer_email' => 'old@example.com',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        $incident = $this->createIncidentForOrder($actor, $order);

        app(CustomerCorrectionService::class)->apply(
            $order,
            new CustomerCorrectionData(
                customerName: 'First Name',
                customerPhone: '9876543210',
                customerEmail: 'old@example.com',
                reason: 'First correction.',
            ),
            $actor,
        );

        app(CustomerCorrectionService::class)->apply(
            $order->fresh(),
            new CustomerCorrectionData(
                customerName: 'Second Name',
                customerPhone: '9876543210',
                customerEmail: 'old@example.com',
                reason: 'Second correction.',
            ),
            $actor,
        );

        $timeline = app(Customer360TimelineService::class)->forIncident($incident->fresh());

        $protectionEvents = $timeline->events()
            ->filter(fn ($event): bool => $event->title === 'Customer Name protected from external sync');

        $this->assertCount(1, $protectionEvents);
        $this->assertSame(TimelineEventType::Synchronization, $protectionEvents->first()->type);

        $correctionEvents = $timeline->events()
            ->filter(fn ($event): bool => $event->title === 'Customer details corrected');

        $this->assertCount(2, $correctionEvents);
    }

    #[Test]
    public function super_admin_can_unlock_protected_identity_fields(): void
    {
        $actor = $this->adminUser();
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-UNLOCK',
            'customer_name' => 'Locked Name',
            'customer_name_locked_at' => now(),
            'customer_name_locked_by' => $actor->id,
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        $unlocked = app(OrderIdentityProtectionService::class)->unlockProtectedIdentityFields(
            $order,
            $superAdmin,
            ['customer_name'],
        );

        $this->assertFalse($unlocked->isCustomerNameLocked());
        $this->assertNull($unlocked->customer_name_locked_at);
        $this->assertNull($unlocked->customer_name_locked_by);
    }

    #[Test]
    public function non_super_admin_cannot_unlock_protected_identity_fields(): void
    {
        $admin = $this->adminUser();

        $order = Order::query()->create([
            'order_id' => 'RD-UNLOCK-DENY',
            'customer_name' => 'Locked Name',
            'customer_name_locked_at' => now(),
            'customer_name_locked_by' => $admin->id,
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $this->expectException(AuthorizationException::class);

        app(OrderIdentityProtectionService::class)->unlockProtectedIdentityFields(
            $order,
            $admin,
            ['customer_name'],
        );
    }

    private function adminUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $user;
    }

    /**
     * @return array{0: User, 1: Incident}
     */
    private function createIncidentWithSerial(User $admin, string $serial): array
    {
        $order = Order::query()->create([
            'order_id' => 'RD-SERIAL-CORRECT',
            'serial_number' => $serial,
            'serial_entered_at' => now(),
            'serial_entered_by_user_id' => $admin->id,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $incident = $this->createIncidentForOrder($admin, $order);

        return [$admin, $incident];
    }

    private function createIncidentForOrder(User $actor, Order $order): Incident
    {
        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Protection test case',
            'description' => '',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }
}
