<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\NotificationType;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Enums\SupportAppointmentStatus;
use App\Enums\SupportAppointmentTimeSlot;
use App\Models\Incident;
use App\Models\Order;
use App\Models\SupportAppointment;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WhatsAppTemplateDispatch;
use App\Services\IncidentReferenceService;
use App\Services\MissingSerial\MissingSerialAutomationAuditService;
use App\Services\Notifications\NotificationAuditTrailService;
use App\Services\Notifications\SerialNotificationAppointmentEligibilityService;
use App\Services\SystemSettingsService;
use App\Services\Timeline\Customer360TimelineService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SerialNotificationAppointmentEligibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-07-05 14:00:00');

        config([
            'missing_serial.enabled' => true,
            'missing_serial.first_delay_minutes' => 15,
            'interakt.api_key' => 'test-interakt-key',
            'interakt.base_url' => 'https://api.interakt.ai',
            'interakt.templates.request_serial_number.name' => 'order_update_request_serial',
            'interakt.templates.request_correct_serial.name' => 'order_update_request_correct_serial',
            'interakt.templates.request_correct_serial.language_code' => 'en',
            'mail.enabled' => true,
            'mail.default' => 'array',
        ]);

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-appointment-guard-001'], 200),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_missing_serial_automation_still_sends_when_no_appointment_exists(): void
    {
        $this->enableNotificationChannels();
        Mail::fake();

        $order = $this->createEligibleMissingSerialOrder(paymentMinutesAgo: 20);

        Artisan::call('missing-serial:process');

        $order->refresh();

        $this->assertDatabaseHas('audit_logs', [
            'auditable_id' => $order->id,
            'event' => MissingSerialAutomationAuditService::EVENT_REQUEST_SENT,
        ]);
        $this->assertSame(1, WhatsAppTemplateDispatch::query()->where('order_id', $order->id)->count());
        $this->assertDatabaseMissing('audit_logs', [
            'event' => NotificationAuditTrailService::EVENT_SKIPPED,
        ]);
    }

    public function test_missing_serial_automation_skips_when_active_appointment_exists(): void
    {
        $this->enableNotificationChannels();
        Mail::fake();

        $order = $this->createEligibleMissingSerialOrder(paymentMinutesAgo: 20);
        $incident = $order->latestIncident();

        $this->createAppointment($incident, SupportAppointmentStatus::Scheduled);

        Artisan::call('missing-serial:process');

        $order->refresh();

        $this->assertNull($order->missing_serial_automation_status);
        $this->assertSame(0, WhatsAppTemplateDispatch::query()->where('order_id', $order->id)->count());
        $this->assertDatabaseHas('audit_logs', [
            'auditable_id' => $incident->id,
            'event' => NotificationAuditTrailService::EVENT_SKIPPED,
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'auditable_id' => $order->id,
            'event' => MissingSerialAutomationAuditService::EVENT_REQUEST_SENT,
        ]);
    }

    public function test_missing_serial_automation_still_sends_when_appointment_is_cancelled(): void
    {
        $this->enableNotificationChannels();
        Mail::fake();

        $order = $this->createEligibleMissingSerialOrder(paymentMinutesAgo: 20);
        $incident = $order->latestIncident();

        $this->createAppointment($incident, SupportAppointmentStatus::Cancelled);

        Artisan::call('missing-serial:process');

        $order->refresh();

        $this->assertSame('requested', $order->missing_serial_automation_status);
        $this->assertSame(1, WhatsAppTemplateDispatch::query()->where('order_id', $order->id)->count());
    }

    public function test_missing_serial_automation_still_sends_when_appointment_is_completed(): void
    {
        $this->enableNotificationChannels();
        Mail::fake();

        $order = $this->createEligibleMissingSerialOrder(paymentMinutesAgo: 20);
        $incident = $order->latestIncident();

        $this->createAppointment($incident, SupportAppointmentStatus::Completed);

        Artisan::call('missing-serial:process');

        $order->refresh();

        $this->assertSame('requested', $order->missing_serial_automation_status);
        $this->assertSame(1, WhatsAppTemplateDispatch::query()->where('order_id', $order->id)->count());
    }

    public function test_manual_request_serial_still_sends_without_appointment(): void
    {
        $this->enableNotificationChannels();
        Mail::fake();

        [$agent, $incident] = $this->createMissingSerialIncident();

        $this->actingAs($agent)
            ->postJson(route('incidents.workspace.request-serial', $incident), [
                'workspace_context' => 'customer',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(1, WhatsAppTemplateDispatch::query()->where('order_id', $incident->order_id)->count());
        $this->assertDatabaseMissing('audit_logs', [
            'event' => NotificationAuditTrailService::EVENT_SKIPPED,
        ]);
    }

    public function test_manual_request_serial_is_skipped_with_active_appointment(): void
    {
        $this->enableNotificationChannels();

        [$agent, $incident] = $this->createMissingSerialIncident();
        $this->createAppointment($incident, SupportAppointmentStatus::Scheduled);

        $this->actingAs($agent)
            ->postJson(route('incidents.workspace.request-serial', $incident), [
                'workspace_context' => 'customer',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', SerialNotificationAppointmentEligibilityService::SKIP_REASON);

        $this->assertSame(0, WhatsAppTemplateDispatch::query()->where('order_id', $incident->order_id)->count());
        $this->assertDatabaseHas('audit_logs', [
            'auditable_id' => $incident->id,
            'event' => NotificationAuditTrailService::EVENT_SKIPPED,
        ]);
    }

    public function test_manual_request_correct_serial_is_skipped_with_active_appointment(): void
    {
        $this->enableNotificationChannels();

        [$agent, $incident] = $this->createSuspiciousSerialIncident();
        $this->createAppointment($incident, SupportAppointmentStatus::Scheduled);

        $this->actingAs($agent)
            ->postJson(route('incidents.workspace.request-correct-serial', $incident), [
                'workspace_context' => 'customer',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', SerialNotificationAppointmentEligibilityService::SKIP_REASON);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_id' => $incident->id,
            'event' => NotificationAuditTrailService::EVENT_SKIPPED,
        ]);
    }

    public function test_skip_appears_on_customer_timeline(): void
    {
        $this->enableNotificationChannels();

        [$agent, $incident] = $this->createMissingSerialIncident();
        $this->createAppointment($incident, SupportAppointmentStatus::Scheduled);

        $this->actingAs($agent)
            ->postJson(route('incidents.workspace.request-serial', $incident), [
                'workspace_context' => 'customer',
            ])
            ->assertUnprocessable();

        $timeline = app(Customer360TimelineService::class)->forIncident($incident->fresh());
        $skippedEvent = $timeline->events()->first(
            fn ($event): bool => str_contains($event->title, 'Requested Device Serial Number skipped'),
        );

        $this->assertNotNull($skippedEvent);
        $this->assertStringContainsString('Active support appointment scheduled', (string) $skippedEvent->detail);
    }

    private function createEligibleMissingSerialOrder(int $paymentMinutesAgo): Order
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-APPT-'.uniqid(),
            'cashfree_payment_id' => 'cf_pay_'.uniqid(),
            'payment_date' => now()->subMinutes($paymentMinutesAgo),
            'serial_number' => null,
            'product_name' => 'MFS 110',
            'device_model' => null,
            'customer_name' => 'Appointment Guard Customer',
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

    /**
     * @return array{0: User, 1: Incident}
     */
    private function createMissingSerialIncident(): array
    {
        $this->enableNotificationChannels();

        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-MANUAL-APPT-'.uniqid(),
            'serial_number' => null,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Manual Serial Customer',
            'customer_phone' => '9876543211',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Manual serial request',
            'description' => 'Manual serial request.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        return [$agent, $incident];
    }

    /**
     * @return array{0: User, 1: Incident}
     */
    private function createSuspiciousSerialIncident(): array
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-CORRECT-APPT-'.uniqid(),
            'serial_number' => '54SAXXC5514586',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Correct Serial Customer',
            'customer_phone' => '9876543212',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Correct serial request',
            'description' => 'Correct serial request.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        return [$agent, $incident];
    }

    private function createAppointment(Incident $incident, SupportAppointmentStatus $status): SupportAppointment
    {
        return SupportAppointment::query()->create([
            'incident_id' => $incident->id,
            'preferred_date' => now()->addDay()->toDateString(),
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning,
            'phone_number' => '9876543210',
            'normalized_phone' => '9876543210',
            'status' => $status,
        ]);
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
