<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\TeamAvailabilityStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\Operations\PresenceEngineService;
use App\Notifications\HighPriorityServiceCaseNotification;
use App\Notifications\ServiceCaseAssignedNotification;
use App\Notifications\ServiceCaseReassignedNotification;
use App\Notifications\TransactionCompletedNotification;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    private function configureAssignee(User $admin): void
    {
        app(\App\Services\SettingService::class)->setMany([
            'assignment.timezone' => config('app.timezone'),
            'assignment.day_shift_start' => '09:00',
            'assignment.day_shift_end' => '18:30',
            'assignment.day_shift_admin_user_id' => (string) $admin->id,
            'assignment.night_shift_admin_user_id' => (string) $admin->id,
            'assignment.fallback_admin_1_user_id' => '',
            'assignment.fallback_admin_2_user_id' => '',
        ]);
    }

    private function createAssigneeAdmin(): User
    {
        $admin = User::factory()->create([
            'name' => 'Avinash Jha',
            'email' => 'admin-assignee@test.com',
        ]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $this->configureAssignee($admin);

        return $admin;
    }

    public function test_quick_create_sends_assigned_and_high_priority_notifications(): void
    {
        Notification::fake();

        $this->createAssigneeAdmin();

        $agent = User::factory()->create([
            'name' => 'Agent User',
            'availability_status' => TeamAvailabilityStatus::Available,
            'availability_updated_at' => now(),
        ]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        app(PresenceEngineService::class)->startSession($agent);
        $agent = $agent->fresh();

        $response = $this->actingAs($agent)->post(route('service-requests.quick.store'), [
            'action' => 'new_contact',
            'intent' => \App\Enums\NewContactIntent::ExistingDeviceService->value,
            'customer_name' => 'High Priority Customer',
            'serial_number' => '7881961',
            'product' => 'MFS 110',
            'source' => IncidentSource::Call->value,
            'notes' => 'High priority device service request.',
            'high_priority' => '1',
        ]);

        $incident = Incident::query()->first();
        $this->assertNotNull($incident);

        $response->assertRedirect(route('dashboard'));

        Notification::assertSentTo($agent, ServiceCaseAssignedNotification::class);
        Notification::assertSentTo($agent, HighPriorityServiceCaseNotification::class);
    }

    public function test_reassignment_sends_notification_to_new_assignee(): void
    {
        Notification::fake();

        $assignee = $this->createAssigneeAdmin();
        $otherAdmin = User::factory()->create(['name' => 'Shipra Kumari']);
        $otherAdmin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-NOTIFY-REASSIGN',
            'serial_number' => 'SN-NOTIFY-REASSIGN',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-NOTIFY-REASSIGN',
            'category' => 'General',
            'source' => IncidentSource::Email,
            'title' => 'Reassign notification test',
            'description' => 'Reassign notification test.',
            'status' => 'open',
            'assigned_to_user_id' => $assignee->id,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->patch(route('incidents.assignment.update', $incident), [
                'assigned_to_user_id' => $otherAdmin->id,
            ])
            ->assertRedirect(route('incidents.show', $incident));

        Notification::assertSentTo($otherAdmin, ServiceCaseReassignedNotification::class);
        Notification::assertNotSentTo($assignee, ServiceCaseReassignedNotification::class);
    }

    public function test_transaction_assignment_notifies_creator_and_assignee(): void
    {
        Notification::fake();

        $assignee = $this->createAssigneeAdmin();

        $agent = User::factory()->create(['name' => 'Creator Agent']);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-NOTIFY-TXN',
            'serial_number' => 'SN-NOTIFY-TXN',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'cashfree_payment_id' => 'cf_notify_txn',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-NOTIFY-TXN',
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Transaction notification test',
            'description' => 'Transaction notification test.',
            'status' => 'open',
            'assigned_to_user_id' => $assignee->id,
            'created_by' => $agent->id,
        ]);

        $this->actingAs($admin)
            ->post(route('orders.transaction.store', $order), [
                'transaction_id' => 'TX-NOTIFY-001',
            ])
            ->assertRedirect(route('orders.show', $order));

        Notification::assertSentTo($agent, TransactionCompletedNotification::class);
        Notification::assertSentTo($assignee, TransactionCompletedNotification::class);
    }

    public function test_navbar_shows_unread_notification_count(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $user->notify(new ServiceCaseAssignedNotification(
            Incident::query()->create([
                'order_id' => Order::query()->create([
                    'order_id' => 'RD-COUNT-1',
                    'serial_number' => 'SN-COUNT-1',
                    'product_name' => 'MFS 110',
                    'device_model' => 'MFS 110',
                    'status' => 'active',
                    'created_by' => $user->id,
                ])->id,
                'reference_no' => app(IncidentReferenceService::class)->generate(),
                'category' => 'General',
                'source' => IncidentSource::Call,
                'title' => 'Count test',
                'description' => 'Count test.',
                'status' => 'open',
                'created_by' => $user->id,
            ]),
            $user,
        ));

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('notification-count-badge', false)
            ->assertSee('View All Notifications');

        $this->assertSame(1, $user->fresh()->unreadNotifications()->count());
    }

    public function test_notification_show_marks_as_read_and_redirects(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-READ-1',
            'serial_number' => 'SN-READ-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-READ-1',
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Read test',
            'description' => 'Read test.',
            'status' => 'open',
            'created_by' => $user->id,
        ]);

        $user->notify(new ServiceCaseAssignedNotification($incident, $user));

        $notification = $user->notifications()->first();
        $this->assertNotNull($notification);
        $this->assertNull($notification->read_at);

        $this->actingAs($user)
            ->get(route('notifications.show', $notification->id))
            ->assertRedirect(route('incidents.show', $incident));

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_notifications_index_supports_mark_all_as_read(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-READ-ALL',
            'serial_number' => 'SN-READ-ALL',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-READ-ALL',
            'category' => 'General',
            'source' => IncidentSource::Email,
            'title' => 'Read all test',
            'description' => 'Read all test.',
            'status' => 'open',
            'created_by' => $user->id,
        ]);

        $user->notify(new ServiceCaseAssignedNotification($incident, $user));
        $user->notify(new HighPriorityServiceCaseNotification($incident, $user));

        $this->assertSame(2, $user->unreadNotifications()->count());

        $this->actingAs($user)
            ->post(route('notifications.read-all'))
            ->assertRedirect(route('notifications.index'))
            ->assertSessionHas('status', 'notifications-read-all');

        $this->assertSame(0, $user->fresh()->unreadNotifications()->count());
    }

    public function test_user_cannot_access_another_users_notification(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $otherUser = User::factory()->create();
        $otherUser->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-VIS-1',
            'serial_number' => 'SN-VIS-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $owner->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-VIS-1',
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Visibility test',
            'description' => 'Visibility test.',
            'status' => 'open',
            'created_by' => $owner->id,
        ]);

        $owner->notify(new ServiceCaseAssignedNotification($incident, $owner));

        $notificationId = $owner->notifications()->first()->id;

        $this->actingAs($otherUser)
            ->get(route('notifications.show', $notificationId))
            ->assertNotFound();
    }
}
