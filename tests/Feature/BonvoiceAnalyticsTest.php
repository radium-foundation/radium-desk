<?php

namespace Tests\Feature;

use App\Models\BonvoiceCallEvent;
use App\Models\Order;
use App\Models\User;
use App\Services\Bonvoice\BonvoiceAnalyticsService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class BonvoiceAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        Carbon::setTestNow(Carbon::parse('2026-07-08 15:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_analytics_totals_are_correct_for_today(): void
    {
        $this->seedCallEvent('call-answered-1', 'ANSWERED', '9876500001', '08448423001', ['CallDuration' => '120']);
        $this->seedCallEvent('call-answered-2', 'COMPLETED', '9876500002', '08448423001', ['CallDuration' => '60']);
        $this->seedCallEvent('call-missed-1', 'NOANSWER', '9876500003', '08448423001');
        $this->seedCallEvent('call-missed-2', 'NOINPUT', '9876500004', '08448423002');
        $this->seedCallEvent('call-missed-3', 'FAILED', '9876500005', '08448423002');
        $this->seedCallEvent('call-yesterday', 'NOANSWER', '9876500006', '08448423001', startedAt: now()->subDay());

        $health = app(BonvoiceAnalyticsService::class)->widgets(useCache: false)['ivr_health'];

        $this->assertSame(5, $health['total_calls']);
        $this->assertSame(2, $health['answered_count']);
        $this->assertSame(40.0, $health['answered_percent']);
        $this->assertSame(3, $health['missed_count']);
        $this->assertSame(60.0, $health['missed_percent']);
        $this->assertSame(90, $health['average_duration_seconds']);
        $this->assertSame('1m 30s', $health['average_duration_label']);
    }

    public function test_agent_mapping_works_via_bonvoice_extension(): void
    {
        $agent = User::factory()->create([
            'name' => 'Avinash',
            'bonvoice_extension' => '08448423017',
        ]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $otherAgent = User::factory()->create([
            'name' => 'Priya',
            'bonvoice_extension' => '08448423018',
        ]);
        $otherAgent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->seedCallEvent('call-agent-1', 'ANSWERED', '9876500101', '08448423017');
        $this->seedCallEvent('call-agent-2', 'NOANSWER', '9876500102', '08448423017');
        $this->seedCallEvent('call-agent-3', 'ANSWERED', '9876500103', '08448423018');
        $this->seedCallEvent('call-agent-4', 'FAILED', '9876500104', '08448423018');
        $this->seedCallEvent('call-agent-5', 'ANSWERED', '9876500105', '08448423018');

        $agents = app(BonvoiceAnalyticsService::class)->widgets(useCache: false)['agent_performance'];

        $this->assertCount(2, $agents);

        $avinash = collect($agents)->firstWhere('agent_name', 'Avinash');
        $priya = collect($agents)->firstWhere('agent_name', 'Priya');

        $this->assertNotNull($avinash);
        $this->assertSame(2, $avinash['total_calls']);
        $this->assertSame(1, $avinash['answered_count']);
        $this->assertSame(1, $avinash['missed_count']);

        $this->assertNotNull($priya);
        $this->assertSame(3, $priya['total_calls']);
        $this->assertSame(2, $priya['answered_count']);
        $this->assertSame(1, $priya['missed_count']);
    }

    public function test_missed_calls_are_detected_with_matched_orders(): void
    {
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-IVR-1',
            'serial_number' => 'SN-IVR-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Missed Caller',
            'customer_phone' => '9876500201',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        $this->seedCallEvent('call-missed-match', 'NOANSWER', '9876500201', '08448423017');
        $this->seedCallEvent('call-missed-unknown', 'FAILED', '9876500999', '08448423017');

        $missedCalls = app(BonvoiceAnalyticsService::class)->widgets(useCache: false)['missed_calls'];

        $this->assertCount(2, $missedCalls);

        $matched = collect($missedCalls)->firstWhere('call_id', 'call-missed-match');
        $unknown = collect($missedCalls)->firstWhere('call_id', 'call-missed-unknown');

        $this->assertNotNull($matched);
        $this->assertSame($order->id, $matched['order_id']);
        $this->assertSame('RD-IVR-1', $matched['order_label']);
        $this->assertNotNull($matched['order_url']);

        $this->assertNotNull($unknown);
        $this->assertNull($unknown['order_id']);
        $this->assertNull($unknown['order_label']);
    }

    public function test_operations_dashboard_performance_tab_includes_ivr_widgets(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($admin)
            ->getJson(route('admin.operations.live', ['groups' => 'performance']))
            ->assertOk()
            ->assertSee('Today\'s IVR Health', false)
            ->assertSee('Agent Call Performance', false)
            ->assertSee('Missed Call Watch', false);
    }

    public function test_analytics_widgets_are_cached(): void
    {
        Cache::flush();

        $service = app(BonvoiceAnalyticsService::class);

        $this->seedCallEvent('call-cache-1', 'ANSWERED', '9876500301', '08448423017');

        $first = $service->widgets();
        $this->assertSame(1, $first['ivr_health']['total_calls']);

        BonvoiceCallEvent::query()->create([
            'call_id' => 'call-cache-2',
            'leg' => 'A',
            'customer_phone' => '9876500302',
            'destination_number' => '08448423017',
            'direction' => 'Inbound',
            'status' => 'ANSWERED',
            'started_at' => now(),
            'payload' => [],
        ]);

        $cached = $service->widgets();
        $this->assertSame(1, $cached['ivr_health']['total_calls']);

        $fresh = $service->widgets(useCache: false);
        $this->assertSame(2, $fresh['ivr_health']['total_calls']);
    }

    /**
     * @param  array<string, mixed>  $payloadOverrides
     */
    private function seedCallEvent(
        string $callId,
        string $status,
        string $customerPhone,
        string $destinationNumber,
        array $payloadOverrides = [],
        ?Carbon $startedAt = null,
    ): BonvoiceCallEvent {
        return BonvoiceCallEvent::query()->create([
            'call_id' => $callId,
            'leg' => 'A',
            'customer_phone' => $customerPhone,
            'destination_number' => $destinationNumber,
            'direction' => 'Inbound',
            'status' => $status,
            'started_at' => $startedAt ?? now(),
            'payload' => array_merge([
                'callID' => $callId,
                'Status' => $status,
                'Direction' => 'Inbound',
            ], $payloadOverrides),
        ]);
    }
}
