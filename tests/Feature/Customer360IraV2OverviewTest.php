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

class Customer360IraV2OverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'interakt.api_key' => 'test-interakt-key',
            'interakt.templates.request_serial_number.name' => 'order_update_request_serial',
            'interakt.templates.request_correct_serial.name' => 'order_update_request_correct_serial',
            'ira.case_intelligence_engine.enabled' => true,
            'ira.v2.enabled' => false,
        ]);

        $this->seed(RolePermissionSeeder::class);
        $this->withHeaders(['Sec-Fetch-Site' => 'same-origin']);
    }

    public function test_overview_uses_legacy_command_center_when_v2_flag_is_off(): void
    {
        [$agent, $incident] = $this->createIncident();

        $summaryHtml = (string) $this->actingAs($agent)
            ->getJson(route('dashboard.service-cases.customer-360.executive-summary', $incident))
            ->assertOk()
            ->json('html');

        $this->assertStringContainsString('data-ira-panel', $summaryHtml);
        $this->assertStringNotContainsString('data-ira-v2-overview', $summaryHtml);
        $this->assertStringNotContainsString('data-ira-v2-signal-bar', $summaryHtml);
        $this->assertStringContainsString('Executive Brief', $summaryHtml);
        $this->assertStringContainsString('Operations briefing', $summaryHtml);
    }

    public function test_overview_renders_signal_bar_when_v2_flag_is_on(): void
    {
        config(['ira.v2.enabled' => true]);

        [$agent, $incident] = $this->createIncident();

        $summaryHtml = (string) $this->actingAs($agent)
            ->getJson(route('dashboard.service-cases.customer-360.executive-summary', $incident))
            ->assertOk()
            ->json('html');

        $this->assertStringContainsString('data-ira-v2-overview', $summaryHtml);
        $this->assertStringContainsString('data-ira-v2-signal-bar', $summaryHtml);
        $this->assertStringContainsString('data-ira-v2-comm-counts', $summaryHtml);
        $this->assertStringContainsString('Case intelligence', $summaryHtml);
        $this->assertStringContainsString('Next best action', $summaryHtml);
        $this->assertStringContainsString('Confidence', $summaryHtml);
        $this->assertStringContainsString('Sentiment', $summaryHtml);
        $this->assertStringContainsString('supporting evidence', $summaryHtml);
        $this->assertStringNotContainsString('Executive Brief', $summaryHtml);
        $this->assertStringNotContainsString('Operations briefing', $summaryHtml);
    }

    public function test_disabling_v2_flag_rolls_back_to_legacy_overview(): void
    {
        config(['ira.v2.enabled' => true]);
        [$agent, $incident] = $this->createIncident();

        $v2Html = (string) $this->actingAs($agent)
            ->getJson(route('dashboard.service-cases.customer-360.executive-summary', $incident))
            ->assertOk()
            ->json('html');

        $this->assertStringContainsString('data-ira-v2-overview', $v2Html);

        config(['ira.v2.enabled' => false]);

        $legacyHtml = (string) $this->actingAs($agent)
            ->getJson(route('dashboard.service-cases.customer-360.executive-summary', $incident))
            ->assertOk()
            ->json('html');

        $this->assertStringNotContainsString('data-ira-v2-overview', $legacyHtml);
        $this->assertStringContainsString('Executive Brief', $legacyHtml);
    }

    /**
     * @return array{0: User, 1: Incident}
     */
    private function createIncident(): array
    {
        $agent = User::factory()->create(['name' => 'V2 Agent']);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-IRA-V2-OV',
            'serial_number' => null,
            'product_name' => 'FM220',
            'device_model' => 'FM220',
            'customer_name' => 'V2 Overview Customer',
            'customer_email' => 'v2@example.com',
            'customer_phone' => '9122222222',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'IRA v2 overview case',
            'description' => 'Missing serial.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
            'created_at' => now()->subDays(3),
        ]);

        return [$agent, $incident];
    }
}
