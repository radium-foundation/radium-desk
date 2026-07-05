<?php

namespace Tests\Feature\MissingSerial;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\MissingSerialAutomationStatus;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WhatsAppTemplateDispatch;
use App\Services\IncidentReferenceService;
use App\Services\MissingSerial\MissingSerialAutomationAuditService;
use App\Services\MissingSerial\MissingSerialAutomationService;
use App\Services\OrderSerialService;
use App\Services\SystemSettingsService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MissingSerialAutomationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-07-05 14:00:00');

        config([
            'missing_serial.enabled' => true,
            'missing_serial.first_delay_minutes' => 45,
            'missing_serial.reminder_delay_hours' => 24,
            'missing_serial.escalation_delay_hours' => 72,
            'cashfree.system_user_email' => 'superadmin@radium.local',
            'interakt.api_key' => 'test-interakt-key',
            'interakt.base_url' => 'https://api.interakt.ai',
            'interakt.templates.request_serial_number.name' => 'order_update_request_serial',
            'interakt.templates.request_serial_number.display_name' => 'Order Update',
            'interakt.templates.request_serial_number.language_code' => 'en',
            'mail.enabled' => true,
            'mail.default' => 'array',
        ]);

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-missing-serial-001'], 200),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_paid_order_missing_serial_sends_after_45_minutes(): void
    {
        $this->enableNotificationChannels();

        $order = $this->createEligibleOrder(paymentMinutesAgo: 46);

        Artisan::call('missing-serial:process');

        $order->refresh();

        $this->assertSame(MissingSerialAutomationStatus::Requested->value, $order->missing_serial_automation_status);
        $this->assertNotNull($order->missing_serial_first_requested_at);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_id' => $order->id,
            'event' => MissingSerialAutomationAuditService::EVENT_REQUEST_SENT,
        ]);

        $this->assertSame(1, WhatsAppTemplateDispatch::query()->where('order_id', $order->id)->count());
    }

    public function test_does_not_send_before_45_minutes(): void
    {
        $this->enableNotificationChannels();

        $order = $this->createEligibleOrder(paymentMinutesAgo: 30);

        Artisan::call('missing-serial:process');

        $order->refresh();

        $this->assertNull($order->missing_serial_automation_status);
        $this->assertSame(0, WhatsAppTemplateDispatch::query()->where('order_id', $order->id)->count());
    }

    public function test_does_not_send_if_radiumbox_later_finds_serial(): void
    {
        $this->enableNotificationChannels();

        $order = $this->createEligibleOrder(paymentMinutesAgo: 60);

        $order->update([
            'serial_number' => 'M250546898',
            'serial_entered_at' => now(),
        ]);

        Artisan::call('missing-serial:process');

        $this->assertSame(0, WhatsAppTemplateDispatch::query()->where('order_id', $order->id)->count());
        $this->assertDatabaseMissing('audit_logs', [
            'auditable_id' => $order->id,
            'event' => MissingSerialAutomationAuditService::EVENT_REQUEST_SENT,
        ]);
    }

    public function test_does_not_duplicate_whatsapp(): void
    {
        $this->enableNotificationChannels();

        $order = $this->createEligibleOrder(paymentMinutesAgo: 60);

        Artisan::call('missing-serial:process');
        Artisan::call('missing-serial:process');

        $this->assertSame(1, WhatsAppTemplateDispatch::query()->where('order_id', $order->id)->count());
        $this->assertSame(1, AuditLog::query()
            ->where('auditable_id', $order->id)
            ->where('event', MissingSerialAutomationAuditService::EVENT_REQUEST_SENT)
            ->count());
    }

    public function test_sends_reminder_after_24_hours(): void
    {
        $this->enableNotificationChannels();

        $order = $this->createEligibleOrder(paymentMinutesAgo: 60);
        $firstRequestedAt = now()->subHours(25);

        $order->update([
            'missing_serial_automation_status' => MissingSerialAutomationStatus::Requested->value,
            'missing_serial_first_requested_at' => $firstRequestedAt,
            'missing_serial_last_contacted_at' => $firstRequestedAt,
        ]);

        Artisan::call('missing-serial:process');

        $order->refresh();

        $this->assertSame(MissingSerialAutomationStatus::Reminded->value, $order->missing_serial_automation_status);
        $this->assertDatabaseHas('audit_logs', [
            'auditable_id' => $order->id,
            'event' => MissingSerialAutomationAuditService::EVENT_REMINDER_SENT,
        ]);
    }

    public function test_escalates_after_72_hours(): void
    {
        $coordinator = User::factory()->create(['is_active' => true]);
        $coordinator->assignRole(RolePermissionSeeder::ROLE_CUSTOMER_COORDINATOR);

        $order = $this->createEligibleOrder(paymentMinutesAgo: 60);
        $incident = $order->latestIncident();
        $firstRequestedAt = now()->subHours(73);

        $order->update([
            'missing_serial_automation_status' => MissingSerialAutomationStatus::Reminded->value,
            'missing_serial_first_requested_at' => $firstRequestedAt,
            'missing_serial_last_contacted_at' => $firstRequestedAt,
        ]);

        Artisan::call('missing-serial:process');

        $order->refresh();
        $incident->refresh();

        $this->assertSame(MissingSerialAutomationStatus::Escalated->value, $order->missing_serial_automation_status);
        $this->assertNotNull($order->missing_serial_escalated_at);
        $this->assertSame($coordinator->id, $incident->assigned_to_user_id);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_id' => $order->id,
            'event' => MissingSerialAutomationAuditService::EVENT_ESCALATED,
        ]);
    }

    public function test_manual_serial_entry_stops_automation(): void
    {
        $this->enableNotificationChannels();

        $order = $this->createEligibleOrder(paymentMinutesAgo: 60);
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order->update([
            'missing_serial_automation_status' => MissingSerialAutomationStatus::Requested->value,
            'missing_serial_first_requested_at' => now()->subHour(),
        ]);

        app(OrderSerialService::class)->assignSerialNumber($order, '9655721', $agent);

        $order->refresh();

        $this->assertSame(MissingSerialAutomationStatus::Completed->value, $order->missing_serial_automation_status);
        $this->assertDatabaseHas('audit_logs', [
            'auditable_id' => $order->id,
            'event' => MissingSerialAutomationAuditService::EVENT_COMPLETED,
        ]);

        Artisan::call('missing-serial:process');

        $this->assertSame(0, WhatsAppTemplateDispatch::query()->where('order_id', $order->id)->count());
    }

    public function test_failed_whatsapp_does_not_break_email(): void
    {
        Mail::fake();

        $this->enableNotificationChannels();

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['message' => 'Template failed'], 400),
        ]);

        $order = $this->createEligibleOrder(paymentMinutesAgo: 60);

        Artisan::call('missing-serial:process');

        Mail::assertSent(\App\Mail\NotificationMail::class);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_id' => $order->id,
            'event' => MissingSerialAutomationAuditService::EVENT_REQUEST_SENT,
        ]);
    }

    private function createEligibleOrder(int $paymentMinutesAgo): Order
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-MS-'.uniqid(),
            'cashfree_payment_id' => 'cf_pay_'.uniqid(),
            'payment_date' => now()->subMinutes($paymentMinutesAgo),
            'serial_number' => null,
            'product_name' => 'MFS 110',
            'device_model' => null,
            'customer_name' => 'Executive Summary Customer',
            'customer_email' => 'customer@example.com',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::Synced->value,
            'radiumbox_sync_attempts' => 1,
            'radiumbox_last_sync_at' => now()->subHour(),
            'created_by' => $agent->id,
        ]);

        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Cashfree,
            'title' => 'Cashfree payment — '.$order->order_id,
            'description' => 'Awaiting serial.',
            'status' => IncidentStatus::AwaitingProductDetails,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        return $order->fresh();
    }

    private function enableNotificationChannels(): void
    {
        foreach ([
            'notifications.whatsapp.enabled' => true,
            'notifications.email.enabled' => true,
            'whatsapp.api_enabled' => true,
            'email.api_enabled' => true,
        ] as $key => $value) {
            SystemSetting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => $value ? '1' : '0'],
            );

            app(SystemSettingsService::class)->forget($key);
        }
    }
}
