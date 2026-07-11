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
            'interakt.templates.request_correct_serial.name' => 'order_update_request_correct_serial',
        ]);

        $this->seed(RolePermissionSeeder::class);

        $this->withHeaders(['Sec-Fetch-Site' => 'same-origin']);
    }

    public function test_customer360_renders_executive_summary_before_health_card(): void
    {
        [$agent, $incident] = $this->createIncidentWithoutSerial();

        $response = $this->actingAs($agent)->get(route('dashboard.service-cases.customer-360', $incident));

        $response->assertOk()
            ->assertSee('data-customer-360-executive-summary-lazy', false);

        $summaryHtml = (string) $this->actingAs($agent)
            ->getJson(route('dashboard.service-cases.customer-360.executive-summary', $incident))
            ->assertOk()
            ->json('html');

        $this->assertStringContainsString('data-customer-360-section="executive-summary"', $summaryHtml);
        $this->assertStringContainsString('IRA Command Center', $summaryHtml);
        $this->assertStringContainsString('AI · Read only', $summaryHtml);
        $this->assertStringContainsString('Explain', $summaryHtml);
        $this->assertStringContainsString('IRA Recommendation', $summaryHtml);
        $this->assertStringContainsString('data-ira-summary-lang-toggle', $summaryHtml);
        $this->assertStringContainsString('data-ira-translate-url', $summaryHtml);

        $html = $response->getContent();
        $healthPos = strpos($html, 'data-customer-360-section="health-card"');
        $lazyPos = strpos($html, 'data-customer-360-executive-summary-lazy');

        $this->assertNotFalse($healthPos);
        $this->assertNotFalse($lazyPos);
        $this->assertLessThan($healthPos, $lazyPos);
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

    public function test_executive_summary_includes_serial_intelligence_for_invalid_serial(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-EXEC-INVALID',
            'serial_number' => '54SAXXC5514586',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Invalid Serial Customer',
            'customer_email' => 'invalid@example.com',
            'customer_phone' => '9123456782',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

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

        $summaryHtml = (string) $this->actingAs($agent)
            ->getJson(route('dashboard.service-cases.customer-360.executive-summary', $incident))
            ->assertOk()
            ->json('html');

        $this->assertStringContainsString('data-ira-serial-insight', $summaryHtml);
        $this->assertStringContainsString('product code', $summaryHtml);
        $this->assertStringContainsString('Request the correct serial number from the customer before closing this case.', $summaryHtml);
        $this->assertStringContainsString('Request the customer to share the correct serial number via WhatsApp.', $summaryHtml);
        $this->assertStringContainsString('Send request', $summaryHtml);

        preg_match("/data-ira-summary-en='([^']+)'/", $summaryHtml, $matches);
        $this->assertNotEmpty($matches[1] ?? null);
        $englishPayload = html_entity_decode((string) $matches[1], ENT_QUOTES);
        $this->assertDoesNotMatchRegularExpression('/[\x{0900}-\x{097F}]/u', $englishPayload);
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
