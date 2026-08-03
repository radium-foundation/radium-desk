<?php

namespace Tests\Feature\IncomingEmail;

use App\Enums\IncomingEmailClassification;
use App\Enums\IncomingEmailMessageStatus;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\OrderStatus;
use App\Models\Incident;
use App\Models\IncomingEmailMessage;
use App\Models\Order;
use App\Models\User;
use App\Services\IncomingEmail\IncomingEmailServiceCaseCreateService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IncomingEmailServiceCaseCreateTest extends TestCase
{
    use RefreshDatabase;

    private IncomingEmailServiceCaseCreateService $createService;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        config([
            'inbound_email.auto_create_service_case' => false,
            'cashfree.system_user_email' => 'superadmin@radium.local',
        ]);

        $this->actor = User::factory()->create(['email' => 'superadmin@radium.local']);
        $this->actor->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $dayAdmin = User::factory()->create(['email' => 'day-admin-email-sc@test.com']);
        $dayAdmin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $nightAdmin = User::factory()->create(['email' => 'night-admin-email-sc@test.com']);
        $nightAdmin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        app(\App\Services\SettingService::class)->setMany([
            'assignment.timezone' => config('app.timezone'),
            'assignment.day_shift_start' => '09:00',
            'assignment.day_shift_end' => '18:30',
            'assignment.day_shift_admin_user_id' => (string) $dayAdmin->id,
            'assignment.night_shift_admin_user_id' => (string) $nightAdmin->id,
            'assignment.fallback_admin_1_user_id' => '',
            'assignment.fallback_admin_2_user_id' => '',
            'assignment.communication_intake_primary_user_id' => (string) $dayAdmin->id,
            'assignment.communication_intake_fallback_user_id' => (string) $nightAdmin->id,
        ]);

        $this->createService = app(IncomingEmailServiceCaseCreateService::class);
    }

    public function test_flag_defaults_to_disabled(): void
    {
        $this->assertFalse(config('inbound_email.auto_create_service_case'));
        $this->assertFalse($this->createService->isEnabled());
    }

    public function test_ensure_active_for_order_creates_email_sourced_service_case(): void
    {
        $order = $this->seedProductOrder('known@example.com');

        $result = $this->createService->ensureActiveForOrder(
            order: $order,
            actor: $this->actor,
            classification: IncomingEmailClassification::Support,
            notes: 'Subject: Scanner issue',
            title: 'Inbound email — Scanner issue',
        );

        $this->assertTrue($result['created']);
        $incident = $result['incident'];
        $this->assertSame(IncidentSource::Email, $incident->source);
        $this->assertSame('Service', $incident->category);
        $this->assertSame(IncidentStatus::Open, $incident->status);
        $this->assertNull($incident->assigned_to_user_id);
        $this->assertSame($order->id, $incident->order_id);
        $this->assertSame(1, Incident::query()->where('order_id', $order->id)->count());
    }

    public function test_ensure_active_for_order_is_idempotent_under_serial_calls(): void
    {
        $order = $this->seedProductOrder('known-idem@example.com');

        $first = $this->createService->ensureActiveForOrder(
            order: $order,
            actor: $this->actor,
            classification: IncomingEmailClassification::Support,
        );
        $second = $this->createService->ensureActiveForOrder(
            order: $order,
            actor: $this->actor,
            classification: IncomingEmailClassification::Support,
        );

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame($first['incident']->id, $second['incident']->id);
        $this->assertSame(1, Incident::query()->where('order_id', $order->id)->whereIn(
            'status',
            IncidentStatus::operationallyActive(),
        )->count());
    }

    public function test_concurrent_ensure_active_for_order_creates_only_one_service_case(): void
    {
        $order = $this->seedProductOrder('race@example.com');

        $results = [];

        DB::transaction(function () use ($order, &$results): void {
            // Simulate two workers that both passed a pre-check with no active SC,
            // then contend inside ensureActiveForOrder's lock + recheck.
            $results[] = $this->createService->ensureActiveForOrder(
                order: $order,
                actor: $this->actor,
                classification: IncomingEmailClassification::ExistingCustomer,
            );
            $results[] = $this->createService->ensureActiveForOrder(
                order: $order,
                actor: $this->actor,
                classification: IncomingEmailClassification::ExistingCustomer,
            );
        });

        $this->assertTrue($results[0]['created']);
        $this->assertFalse($results[1]['created']);
        $this->assertSame($results[0]['incident']->id, $results[1]['incident']->id);
        $this->assertSame(1, Incident::query()->where('order_id', $order->id)->count());
    }

    public function test_ensure_for_unknown_customer_creates_inq_prefixed_order_and_email_source(): void
    {
        $message = $this->seedIncomingMessage(
            fromEmail: 'new.lead@example.com',
            fromName: 'New Lead',
            subject: 'Interested in buying',
        );

        $result = $this->createService->ensureForUnknownCustomer(
            message: $message,
            actor: $this->actor,
            classification: IncomingEmailClassification::PossibleSalesLead,
        );

        $this->assertTrue($result['created']);
        $incident = $result['incident'];
        $order = $incident->order;

        $this->assertNotNull($order);
        $this->assertTrue(Order::isInquiryOrderId($order->order_id));
        $this->assertStringStartsWith('INQ-', $order->order_id);
        $this->assertSame('new.lead@example.com', $order->customer_email);
        $this->assertSame(IncidentSource::Email, $incident->source);
        $this->assertSame('Sales Lead', $incident->category);
        $this->assertNull($incident->assigned_to_user_id);
    }

    public function test_ensure_for_unknown_customer_reuses_active_inquiry_for_same_email(): void
    {
        $firstMessage = $this->seedIncomingMessage(
            fromEmail: 'repeat@example.com',
            subject: 'First ask',
        );
        $secondMessage = $this->seedIncomingMessage(
            fromEmail: 'repeat@example.com',
            subject: 'Second ask',
            providerMessageId: 'gmail:msg-2',
        );

        $first = $this->createService->ensureForUnknownCustomer(
            message: $firstMessage,
            actor: $this->actor,
            classification: IncomingEmailClassification::UnknownCustomer,
        );
        $second = $this->createService->ensureForUnknownCustomer(
            message: $secondMessage,
            actor: $this->actor,
            classification: IncomingEmailClassification::UnknownCustomer,
        );

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame($first['incident']->id, $second['incident']->id);
        $this->assertSame(1, Order::query()->where('customer_email', 'repeat@example.com')->count());
        $this->assertSame(1, Incident::query()->count());
    }

    public function test_create_link_and_route_for_order_links_without_duplicate_assignment_on_create(): void
    {
        $order = $this->seedProductOrder('link-route@example.com');
        $message = $this->seedIncomingMessage(
            fromEmail: 'link-route@example.com',
            subject: 'Need help',
        );

        $result = $this->createService->createLinkAndRouteForOrder(
            order: $order,
            message: $message,
            actor: $this->actor,
            classification: IncomingEmailClassification::Support,
        );

        $this->assertTrue($result['created']);
        $this->assertSame(IncidentSource::Email, $result['incident']->source);
        $this->assertSame(IncomingEmailMessageStatus::Linked, $result['message']->status);
        $this->assertSame($result['incident']->id, $result['message']->incident_id);
        // Assigned via Communication Intake after link — not via assignOnCreate alone.
        $this->assertNotNull($result['incident']->assigned_to_user_id);
    }

    public function test_internal_operational_classification_is_rejected(): void
    {
        $order = $this->seedProductOrder('vendor@example.com');

        $this->expectException(\InvalidArgumentException::class);

        $this->createService->ensureActiveForOrder(
            order: $order,
            actor: $this->actor,
            classification: IncomingEmailClassification::VendorAction,
        );
    }

    private function seedProductOrder(string $email): Order
    {
        return Order::query()->create([
            'order_id' => 'RD-EMAIL-SC-'.uniqid(),
            'serial_number' => 'SN-EMAIL-SC-'.uniqid(),
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Email Customer',
            'customer_phone' => '9876501234',
            'customer_email' => $email,
            'status' => OrderStatus::Active,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);
    }

    private function seedIncomingMessage(
        string $fromEmail,
        string $subject,
        ?string $fromName = null,
        string $providerMessageId = 'gmail:msg-1',
    ): IncomingEmailMessage {
        return IncomingEmailMessage::query()->create([
            'provider' => 'gmail',
            'mailbox' => 'support@radiumbox.com',
            'channel' => 'support',
            'provider_message_id' => $providerMessageId,
            'thread_id' => 'thread-'.uniqid(),
            'rfc_message_id' => '<'.uniqid().'@example.com>',
            'from_email' => $fromEmail,
            'from_name' => $fromName,
            'subject' => $subject,
            'preview' => 'Preview body for '.$subject,
            'status' => IncomingEmailMessageStatus::Received,
            'received_at' => now(),
            'attachment_count' => 0,
        ]);
    }
}
