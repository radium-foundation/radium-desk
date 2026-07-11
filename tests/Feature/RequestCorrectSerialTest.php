<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\SerialInsightConfidence;
use App\Enums\SerialInsightStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\Notifications\NotificationAuditTrailService;
use App\Services\SerialValidation\RequestCorrectSerialAuditService;
use App\Services\SerialValidation\SerialInsightService;
use App\Services\SerialValidation\SerialLearningExportService;
use App\Services\SystemSettingsService;
use App\Services\Timeline\Customer360TimelineService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RequestCorrectSerialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'interakt.api_key' => 'test-interakt-key',
            'interakt.base_url' => 'https://api.interakt.ai',
            'interakt.templates.request_serial_number.name' => 'order_update_request_serial',
            'interakt.templates.request_correct_serial.name' => 'order_update_request_correct_serial',
            'interakt.templates.request_correct_serial.language_code' => 'en',
            'mail.enabled' => true,
            'mail.default' => 'array',
        ]);

        $this->seed(RolePermissionSeeder::class);

        $this->withHeaders(['Sec-Fetch-Site' => 'same-origin']);
    }

    public function test_serial_insight_suggests_correct_serial_confirmation_for_suspicious_serial(): void
    {
        $order = $this->createInvalidSerialOrder();

        $insight = app(SerialInsightService::class)->analyze($order);

        $this->assertSame(SerialInsightStatus::Suspicious, $insight->status);
        $this->assertSame(
            'Request the customer to share the correct serial number via WhatsApp.',
            $insight->suggestedAction,
        );
    }

    public function test_serial_learning_export_includes_insight_analysis(): void
    {
        $this->createInvalidSerialOrder();

        $export = app(SerialLearningExportService::class)->export()->toArray();

        $this->assertArrayHasKey('insight_analysis', $export);
        $this->assertArrayHasKey('top_invalid_patterns', $export['insight_analysis']);
        $this->assertArrayHasKey('product_wise_failure_reasons', $export['insight_analysis']);
        $this->assertArrayHasKey('confidence_tuning', $export['insight_analysis']);
        $this->assertArrayHasKey('distribution', $export['insight_analysis']['confidence_tuning']);
    }

    public function test_request_correct_serial_dialog_is_available_for_suspicious_serial(): void
    {
        [$agent, $incident] = $this->createInvalidSerialIncident();

        $this->actingAs($agent)
            ->get(route('incidents.components.show', [$incident, 'request-correct-serial']).'?workspace_context=customer')
            ->assertOk()
            ->assertSee('Request Correct Serial', false)
            ->assertSee('54SAXXC5514586', false)
            ->assertSee('Suspicious', false)
            ->assertSee('Confirm the correct device serial number', false)
            ->assertSee('Send Request', false);
    }

    public function test_customer360_shows_request_correct_serial_quick_action(): void
    {
        [$agent, $incident] = $this->createInvalidSerialIncident();

        $this->actingAs($agent)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->assertSee('Request Serial', false)
            ->assertSee('data-workspace-trigger="request-correct-serial"', false)
            ->assertDontSee('Request Correct Serial', false)
            ->assertDontSee('Request Serial Number', false)
            ->assertDontSee('Serial number missing', false);
    }

    public function test_executive_summary_shows_ira_recommendation_and_send_request_button(): void
    {
        [$agent, $incident] = $this->createInvalidSerialIncident();

        $summaryHtml = (string) $this->actingAs($agent)
            ->getJson(route('dashboard.service-cases.customer-360.executive-summary', $incident))
            ->assertOk()
            ->json('html');

        $this->assertStringContainsString('Request the correct serial number from the customer before closing this case.', $summaryHtml);
        $this->assertStringContainsString('Send request', $summaryHtml);
        $this->assertStringContainsString('data-workspace-trigger="request-correct-serial"', $summaryHtml);
    }

    public function test_manual_request_correct_serial_persists_audit_and_timeline_entries(): void
    {
        [$agent, $incident] = $this->createInvalidSerialIncident();

        $this->enableNotificationChannels([
            'notifications.whatsapp.enabled' => true,
            'notifications.email.enabled' => true,
            'whatsapp.api_enabled' => true,
            'email.api_enabled' => true,
        ]);

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-correct-serial-001'], 200),
        ]);

        $this->actingAs($agent)->postJson(
            route('incidents.workspace.request-correct-serial', $incident),
            ['workspace_context' => 'customer'],
        )->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'event' => NotificationAuditTrailService::EVENT_DISPATCHED,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'event' => RequestCorrectSerialAuditService::EVENT_REQUEST_SENT,
        ]);

        $auditLog = \App\Models\AuditLog::query()
            ->where('event', RequestCorrectSerialAuditService::EVENT_REQUEST_SENT)
            ->where('auditable_id', $incident->id)
            ->first();

        $this->assertNotNull($auditLog);
        $this->assertSame('54SAXXC5514586', $auditLog->new_values['old_serial'] ?? null);
        $this->assertSame(SerialInsightConfidence::High->value, $auditLog->new_values['confidence'] ?? null);
        $this->assertSame(SerialInsightStatus::Suspicious->value, $auditLog->new_values['insight_status'] ?? null);
        $this->assertSame($agent->name, $auditLog->new_values['sent_by'] ?? null);

        $timeline = app(Customer360TimelineService::class)->forIncident($incident->fresh());
        $titles = $timeline->groups
            ->flatMap(fn ($group) => $group->events)
            ->pluck('title')
            ->all();

        $this->assertTrue(
            collect($titles)->contains(fn (string $title): bool => str_contains($title, 'Serial correction')),
        );
    }

    public function test_missing_serial_does_not_show_request_correct_serial_action(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-CORRECT-MISSING',
            'serial_number' => null,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Missing Serial Customer',
            'customer_email' => 'missing@example.com',
            'customer_phone' => '9123456783',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Missing serial case',
            'description' => 'No serial.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->assertDontSee('Request Correct Serial', false);
    }

    /**
     * @return array{0: User, 1: Incident}
     */
    private function createInvalidSerialIncident(): array
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = $this->createInvalidSerialOrder($agent);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Invalid serial case',
            'description' => 'Bad serial.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        return [$agent, $incident];
    }

    private function createInvalidSerialOrder(?User $agent = null): Order
    {
        $agent ??= User::factory()->create();

        return Order::query()->create([
            'order_id' => 'RD-CORRECT-INVALID',
            'serial_number' => '54SAXXC5514586',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Invalid Serial Customer',
            'customer_email' => 'invalid@example.com',
            'customer_phone' => '9123456782',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);
    }

    /**
     * @param  array<string, bool>  $settings
     */
    private function enableNotificationChannels(array $settings): void
    {
        foreach ($settings as $key => $enabled) {
            SystemSetting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => $enabled ? '1' : '0'],
            );
            app(SystemSettingsService::class)->forget($key);
        }
    }
}
