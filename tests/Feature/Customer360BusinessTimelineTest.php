<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\ServiceCaseAutomationMonitorService;
use App\Services\Timeline\Customer360TimelineService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Customer360BusinessTimelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['ira.business_timeline.enabled' => true]);
    }

    public function test_timeline_tab_renders_business_milestones_without_raw_enum_headings(): void
    {
        [$agent, $incident] = $this->createFixture();

        AuditLog::query()->create([
            'user_id' => $agent->id,
            'event' => ServiceCaseAutomationMonitorService::EVENT_PAYMENT_RECEIVED,
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'old_values' => [],
            'new_values' => [],
        ]);

        $html = (string) $this->actingAs($agent)
            ->getJson(route('dashboard.service-cases.customer-360.timeline', $incident).'?tab=1&offset=0')
            ->assertOk()
            ->json('html');

        $this->assertStringContainsString('data-business-timeline', $html);
        $this->assertStringContainsString('data-timeline-milestone', $html);
        $this->assertStringContainsString('Payment received', $html);
        $this->assertStringContainsString('Show Raw Events', $html);
        $this->assertStringContainsString('data-timeline-raw-event', $html);
        $this->assertStringContainsString('data-timeline-search', $html);
        $this->assertStringNotContainsString('whatsapp_template_sent', $html);
    }

    public function test_timeline_search_query_param_filters_results(): void
    {
        [$agent, $incident] = $this->createFixture();

        AuditLog::query()->create([
            'user_id' => $agent->id,
            'event' => ServiceCaseAutomationMonitorService::EVENT_PAYMENT_RECEIVED,
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'old_values' => [],
            'new_values' => [],
        ]);

        $miss = (string) $this->actingAs($agent)
            ->getJson(route('dashboard.service-cases.customer-360.timeline', $incident).'?tab=1&offset=0&q='.urlencode('zzzz-no-match'))
            ->assertOk()
            ->json('html');

        $this->assertStringContainsString('No timeline matches', $miss);

        $hit = (string) $this->actingAs($agent)
            ->getJson(route('dashboard.service-cases.customer-360.timeline', $incident).'?tab=1&offset=0&q='.urlencode('payment'))
            ->assertOk()
            ->json('html');

        $this->assertStringContainsString('Payment received', $hit);
        $this->assertStringNotContainsString('No timeline matches', $hit);
    }

    public function test_for_order_still_returns_flat_timeline_view_model(): void
    {
        [$agent, $incident] = $this->createFixture();
        $order = $incident->order;
        $this->assertNotNull($order);

        $viewModel = app(Customer360TimelineService::class)->forOrder($order);

        $this->assertInstanceOf(\App\Data\TimelineViewModel::class, $viewModel);
    }

    /**
     * @return array{0: User, 1: Incident}
     */
    private function createFixture(): array
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-BIZ-TL',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Business timeline case',
            'description' => 'Business timeline case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        return [$agent, $incident];
    }
}
