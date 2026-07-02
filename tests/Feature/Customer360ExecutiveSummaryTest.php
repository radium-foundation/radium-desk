<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Customer360ExecutiveSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'interakt.api_key' => 'test-interakt-key',
            'interakt.templates.request_serial_number.name' => 'order_update_request_serial',
        ]);

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_customer360_renders_executive_summary_before_health_card(): void
    {
        [$agent, $incident] = $this->createIncidentWithoutSerial();

        $response = $this->actingAs($agent)->get(route('dashboard.service-cases.customer-360', $incident));

        $response->assertOk()
            ->assertSee('data-customer-360-section="executive-summary"', false)
            ->assertSee('IRA Executive Summary', false)
            ->assertSee('Read Only', false)
            ->assertSee('IRA Opinion', false)
            ->assertSee('IRA Recommendation', false)
            ->assertSee('data-ira-summary-lang-toggle', false)
            ->assertSee('data-ira-translate-url', false);

        $html = $response->getContent();
        $executivePos = strpos($html, 'data-customer-360-section="executive-summary"');
        $healthPos = strpos($html, 'data-customer-360-section="health-card"');

        $this->assertNotFalse($executivePos);
        $this->assertNotFalse($healthPos);
        $this->assertLessThan($healthPos, $executivePos);
    }

    public function test_executive_summary_translation_endpoint_returns_hindi_without_rebuilding_drawer(): void
    {
        [$agent, $incident] = $this->createIncidentWithoutSerial();

        $payload = [
            'executive_summary' => [
                'Customer purchased an FM220 and currently has one active repair.',
                'The device serial number is still missing, causing service delay.',
            ],
            'opinion' => 'This appears to be a straightforward serial-number pending case. Obtaining the serial should unblock warranty validation and allow engineering to proceed.',
            'recommendation' => 'Request the serial immediately, verify warranty once received, and proactively update the customer regarding SLA.',
        ];

        $response = $this->actingAs($agent)->postJson(
            route('dashboard.service-cases.customer-360.executive-summary.translate', $incident),
            $payload,
        );

        $response->assertOk()
            ->assertJsonPath('executive_summary.0', fn ($line) => str_contains((string) $line, 'ग्राहक'))
            ->assertJsonPath('opinion', fn ($line) => str_contains((string) $line, 'सीरियल'))
            ->assertJsonPath('recommendation', fn ($line) => str_contains((string) $line, 'तुरंत'));
    }

    /**
     * @return array{0: User, 1: Incident}
     */
    private function createIncidentWithoutSerial(): array
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-EXEC-360',
            'serial_number' => null,
            'product_name' => 'FM220',
            'device_model' => 'FM220',
            'customer_name' => 'Executive Summary Customer',
            'customer_email' => 'exec@example.com',
            'customer_phone' => '9123456780',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Executive summary case',
            'description' => 'Missing serial.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
            'created_at' => now()->subDays(4),
        ]);

        return [$agent, $incident];
    }
}
