<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Enums\ServiceCaseAutomationStatus;
use App\Enums\WhatsAppTemplateDispatchStatus;
use App\Enums\WhatsAppTemplateTriggerSource;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Models\WhatsAppTemplateDispatch;
use App\Services\IncidentReferenceService;
use App\Services\Interakt\RequestSerialNumberEligibilityService;
use App\Services\MissingSerial\MissingSerialAutomationAuditService;
use App\Services\MissingSerial\MissingSerialAutomationService;
use App\Services\ServiceCaseAutomationStatusService;
use App\Services\SystemSettingsService;
use App\Models\SystemSetting;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProductOrderRequestSerialExclusionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-07-05 14:00:00');

        config([
            'missing_serial.enabled' => true,
            'missing_serial.first_delay_minutes' => 45,
            'interakt.api_key' => 'test-interakt-key',
            'interakt.base_url' => 'https://api.interakt.ai',
            'interakt.templates.request_serial_number.name' => 'order_update_request_serial',
            'cashfree.system_user_email' => 'superadmin@radium.local',
        ]);

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-product-order-exclusion'], 200),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_rde_order_does_not_show_request_serial_quick_action(): void
    {
        [$agent, $incident] = $this->createIncident('RDE253851', missingSerial: true);

        $html = $this->actingAs($agent)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->getContent();

        $this->assertFalse(app(RequestSerialNumberEligibilityService::class)->canShowAction($incident));
        $this->assertStringNotContainsString('data-workspace-trigger="request-serial"', $html);
        $this->assertStringNotContainsString('Request Serial Number', $html);
        $this->assertStringNotContainsString('Serial number requested', $html);
    }

    public function test_rde_order_hides_serial_requested_state_even_when_prior_contact_exists(): void
    {
        [$agent, $incident] = $this->createIncident('RDE888001', missingSerial: true);

        WhatsAppTemplateDispatch::query()->create([
            'incident_id' => $incident->id,
            'order_id' => $incident->order_id,
            'triggered_by_user_id' => $agent->id,
            'template_key' => 'request_serial_number',
            'template_name' => 'order_update_request_serial',
            'template_display_name' => 'Order Update',
            'template_purpose' => 'Request Serial Number',
            'trigger_source' => WhatsAppTemplateTriggerSource::Manual,
            'status' => WhatsAppTemplateDispatchStatus::Sent,
            'customer_phone' => '9123456780',
            'interakt_message_id' => 'msg-rde-hidden',
            'dispatched_at' => now(),
        ]);

        $html = $this->actingAs($agent)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Serial number requested', $html);
        $this->assertStringNotContainsString('data-workspace-trigger="request-serial"', $html);
    }

    public function test_rde_order_is_ignored_by_missing_serial_automation(): void
    {
        $this->enableNotificationChannels();

        $order = $this->createEligibleOrder('RDE999001', paymentMinutesAgo: 60);

        Artisan::call('missing-serial:process');

        $this->assertSame(
            'Product orders are excluded from missing serial automation.',
            app(MissingSerialAutomationService::class)->ineligibilityReason($order),
        );
        $this->assertSame(0, WhatsAppTemplateDispatch::query()->where('order_id', $order->id)->count());
        $this->assertDatabaseMissing('audit_logs', [
            'auditable_id' => $order->id,
            'event' => MissingSerialAutomationAuditService::EVENT_REQUEST_SENT,
        ]);
    }

    public function test_rde_order_does_not_enter_waiting_for_customer_serial_status(): void
    {
        [, $incident] = $this->createIncident('RDE777001', missingSerial: true);

        $status = app(ServiceCaseAutomationStatusService::class)->statusFor($incident);

        $this->assertNotSame(ServiceCaseAutomationStatus::WaitingForCustomerSerial, $status);
    }

    public function test_rd_order_missing_serial_still_shows_request_serial_action(): void
    {
        [$agent, $incident] = $this->createIncident('RD-253851', missingSerial: true);

        $this->assertTrue(app(RequestSerialNumberEligibilityService::class)->canShowAction($incident));

        $this->actingAs($agent)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->assertSee('data-workspace-trigger="request-serial"', false)
            ->assertSee('Request Serial Number', false);
    }

    public function test_rd_order_missing_serial_automation_still_works(): void
    {
        $this->enableNotificationChannels();

        $order = $this->createEligibleOrder('RD-MS-'.uniqid(), paymentMinutesAgo: 60);

        Artisan::call('missing-serial:process');

        $this->assertNull(app(MissingSerialAutomationService::class)->ineligibilityReason($order));
        $this->assertSame(1, WhatsAppTemplateDispatch::query()->where('order_id', $order->id)->count());
        $this->assertDatabaseHas('audit_logs', [
            'auditable_id' => $order->id,
            'event' => MissingSerialAutomationAuditService::EVENT_REQUEST_SENT,
        ]);
    }

    public function test_request_serial_modal_is_unauthorized_for_product_order(): void
    {
        [$agent, $incident] = $this->createIncident('RDE555001', missingSerial: true);

        $this->actingAs($agent)
            ->get(route('incidents.components.show', [$incident, 'request-serial']).'?workspace_context=customer')
            ->assertForbidden();
    }

    /**
     * @return array{0: User, 1: Incident}
     */
    private function createIncident(string $orderId, bool $missingSerial = false): array
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => $missingSerial ? null : 'SN-'.uniqid(),
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'cashfree_payment_id' => 'cf_pay_'.uniqid(),
            'payment_date' => now()->subHour(),
            'customer_name' => 'Product Order Customer',
            'customer_email' => 'customer@example.com',
            'customer_phone' => '9123456780',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Cashfree,
            'title' => 'Product order case — '.$orderId,
            'description' => 'Product order case.',
            'status' => IncidentStatus::AwaitingProductDetails,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        return [$agent, $incident];
    }

    private function createEligibleOrder(string $orderId, int $paymentMinutesAgo): Order
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => $orderId,
            'cashfree_payment_id' => 'cf_pay_'.uniqid(),
            'payment_date' => now()->subMinutes($paymentMinutesAgo),
            'serial_number' => null,
            'product_name' => 'MFS 110',
            'device_model' => null,
            'customer_name' => 'Automation Customer',
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
            'title' => 'Cashfree payment — '.$orderId,
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
