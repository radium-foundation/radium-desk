<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\SupportAppointmentTimeSlot;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WhatsAppTemplateDispatch;
use App\Services\IncidentReferenceService;
use App\Services\Interakt\WhatsAppFlowService;
use App\Services\Notifications\NotificationAuditTrailService;
use App\Services\ServiceCaseActivityTimelineService;
use App\Services\SystemSettingsService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\Support\InteractsWithInteraktWebhooks;
use Tests\TestCase;

class SupportAppointmentConfirmationNotificationTest extends TestCase
{
    use InteractsWithInteraktWebhooks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'interakt.api_key' => 'test-interakt-key',
            'interakt.base_url' => 'https://api.interakt.ai',
            'interakt.verify_signature' => true,
            'interakt.webhook_secret' => 'test-interakt-webhook-secret',
            'interakt.flow_id' => '2559716037790863',
            'interakt.flow_token_ttl_hours' => 24,
            'interakt.templates.support_appointment_booked.name' => 'support_appointment_booked',
            'interakt.templates.support_appointment_booked.language_code' => 'en',
            'mail.enabled' => true,
            'mail.default' => 'array',
        ]);

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-support-booked-001'], 200),
        ]);

        $this->seed(RolePermissionSeeder::class);
        $this->enableNotificationChannels([
            'notifications.whatsapp.enabled' => true,
            'notifications.email.enabled' => true,
            'notifications.desktop.enabled' => false,
            'notifications.telegram.enabled' => false,
            'whatsapp.api_enabled' => true,
            'email.api_enabled' => true,
        ]);
    }

    public function test_web_booking_sends_confirmation_notification(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident, $order] = $this->createIncident($agent);

        $storeUrl = URL::temporarySignedRoute(
            'support-appointments.store',
            now()->addDays(30),
            ['incident' => $incident->id],
        );

        $preferredDate = now()->addDay()->toDateString();

        $this->post($storeUrl, [
            'preferred_date' => $preferredDate,
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning->value,
            'phone_number' => '9876543210',
            'additional_notes' => 'Need help with fingerprint setup.',
        ])->assertRedirect();

        $this->assertConfirmationNotificationSent(
            $incident,
            $order,
            $preferredDate,
            SupportAppointmentTimeSlot::Morning,
        );
    }

    public function test_whatsapp_flow_booking_sends_confirmation_notification(): void
    {
        [$incident, $flowToken, $order] = $this->createIncidentWithFlowToken();

        $preferredDate = now()->addDay()->toDateString();

        $payload = $this->officialFlowResponsePayload([
            'flow_token' => $flowToken,
            'preferred_date' => $preferredDate,
            'preferred_time_slot' => SupportAppointmentTimeSlot::Afternoon->value,
            'phone_number' => '9876543210',
            'additional_notes' => 'Booked via WhatsApp Flow.',
        ]);

        $this->postSignedInteraktFlowWebhook($payload)
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);

        $this->assertConfirmationNotificationSent(
            $incident,
            $order,
            $preferredDate,
            SupportAppointmentTimeSlot::Afternoon,
        );
    }

    public function test_confirmation_notification_writes_audit_trail(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident] = $this->createIncident($agent);

        $this->bookViaWeb($incident);

        $auditLog = AuditLog::query()
            ->where('auditable_type', $incident->getMorphClass())
            ->where('auditable_id', $incident->id)
            ->where('event', NotificationAuditTrailService::EVENT_DISPATCHED)
            ->first();

        $this->assertNotNull($auditLog);
        $this->assertSame('support_appointment_booked', $auditLog->new_values['notification_type'] ?? null);
        $this->assertSame('support_appointment_web', $auditLog->new_values['source'] ?? null);
    }

    public function test_confirmation_notification_appears_in_timeline(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident] = $this->createIncident($agent);

        $this->bookViaWeb($incident);

        $timeline = app(ServiceCaseActivityTimelineService::class)->forIncident($incident->fresh());
        $entry = $timeline->first(fn ($item) => $item->title === 'Notification sent');

        $this->assertNotNull($entry);
        $this->assertStringContainsString('✓ WhatsApp', (string) $entry->body);
        $this->assertStringContainsString('✓ Email', (string) $entry->body);
    }

    public function test_confirmation_notification_not_sent_when_booking_validation_fails(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident] = $this->createIncident($agent);

        $storeUrl = URL::temporarySignedRoute(
            'support-appointments.store',
            now()->addDays(30),
            ['incident' => $incident->id],
        );

        $this->post($storeUrl, [
            'preferred_date' => now()->subDay()->toDateString(),
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning->value,
            'phone_number' => '9876543210',
        ])->assertSessionHasErrors('preferred_date');

        $this->assertDatabaseCount('support_appointments', 0);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => NotificationAuditTrailService::EVENT_DISPATCHED,
        ]);
        $this->assertSame(0, WhatsAppTemplateDispatch::query()->count());
    }

    private function bookViaWeb(Incident $incident): void
    {
        $storeUrl = URL::temporarySignedRoute(
            'support-appointments.store',
            now()->addDays(30),
            ['incident' => $incident->id],
        );

        $this->post($storeUrl, [
            'preferred_date' => now()->addDay()->toDateString(),
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning->value,
            'phone_number' => '9876543210',
        ])->assertRedirect();
    }

    private function assertConfirmationNotificationSent(
        Incident $incident,
        Order $order,
        string $preferredDate,
        SupportAppointmentTimeSlot $timeSlot,
    ): void {
        $auditLog = AuditLog::query()
            ->where('auditable_type', $incident->getMorphClass())
            ->where('auditable_id', $incident->id)
            ->where('event', NotificationAuditTrailService::EVENT_DISPATCHED)
            ->first();

        $this->assertNotNull($auditLog);
        $this->assertSame('support_appointment_booked', $auditLog->new_values['notification_type'] ?? null);

        $channelResults = collect($auditLog->new_values['channel_results'] ?? []);
        $this->assertTrue($channelResults->contains(
            fn (array $result): bool => ($result['channel'] ?? null) === 'whatsapp' && ($result['success'] ?? false) === true,
        ));
        $this->assertTrue($channelResults->contains(
            fn (array $result): bool => ($result['channel'] ?? null) === 'email' && ($result['success'] ?? false) === true,
        ));

        $dispatch = WhatsAppTemplateDispatch::query()
            ->where('incident_id', $incident->id)
            ->where('template_key', 'support_appointment_booked')
            ->first();

        $this->assertNotNull($dispatch);
        $this->assertSame([
            $order->customer_name,
            $order->order_id,
            now()->parse($preferredDate)->timezone(config('app.timezone', 'Asia/Kolkata'))->format('d M Y'),
            $timeSlot->label(),
        ], $dispatch->context['body_values'] ?? null);

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return str_contains((string) ($body['template']['name'] ?? ''), 'support_appointment_booked')
                && ($body['template']['bodyValues'] ?? []) !== [];
        });
    }

    /**
     * @return array{0: Incident, 1: Order}
     */
    private function createIncident(User $agent): array
    {
        $order = Order::query()->create([
            'order_id' => 'RD-CONF-'.uniqid(),
            'serial_number' => 'SN-CONF-'.uniqid(),
            'product_name' => 'MFS 110 E3',
            'device_model' => 'MFS 110 E3',
            'transaction_id' => 'TXN-CONF-'.uniqid(),
            'customer_name' => 'Confirmation Customer',
            'customer_email' => 'confirmation@example.com',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Confirmation notification case',
            'description' => 'Confirmation notification case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        return [$incident, $order];
    }

    /**
     * @return array{0: Incident, 1: string, 2: Order}
     */
    private function createIncidentWithFlowToken(): array
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident, $order] = $this->createIncident($agent);
        $flowToken = app(WhatsAppFlowService::class)->generateToken($incident);

        return [$incident, $flowToken, $order];
    }

    /**
     * @param  array<string, bool>  $channels
     */
    private function enableNotificationChannels(array $channels): void
    {
        foreach ($channels as $key => $enabled) {
            SystemSetting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => $enabled ? '1' : '0'],
            );

            app(SystemSettingsService::class)->forget($key);
        }
    }
}
