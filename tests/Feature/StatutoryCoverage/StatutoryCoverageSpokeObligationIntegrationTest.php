<?php

namespace Tests\Feature\StatutoryCoverage;

use App\Data\StatutoryCoverage\StatutoryCoverageReport;
use App\Data\StatutoryCoverage\StatutoryCoverageWindow;
use App\Enums\StatutoryCoverage\StatutoryCoverageClassification;
use App\Models\Order;
use App\Models\User;
use App\Services\StatutoryCoverage\StatutoryCoverageReconciliationService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StatutoryCoverageSpokeObligationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-15 12:00:00');
        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);

        config([
            'statutory_coverage.timezone' => 'Asia/Kolkata',
            'statutory_coverage.obligations.max_pages_per_fetch' => 10,
            'order_lookup.spokes.rdservice_in.enabled' => true,
            'order_lookup.spokes.rdservice_in.base_url' => 'https://rdservice.in.test',
            'order_lookup.spokes.rdservice_in.token' => 'test-token',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_collects_multiple_pages_via_next_cursor(): void
    {
        Http::fake([
            'rdservice.in.test/api/integrations/v1/statutory-obligations*' => Http::sequence()
                ->push($this->spokePage(['RD9101', 'RD9102'], complete: false, next: 'cursor-page-2'))
                ->push($this->spokePage(['RD9103'], complete: true, next: null)),
        ]);

        $report = $this->runReport();

        $this->assertSame(3, $report->channels[0]->sourcePopulation->count);
        $this->assertSame(2, $report->channels[0]->sourcePopulation->pagesFetched);
        $this->assertTrue($report->channels[0]->sourcePopulation->complete);
        $this->assertFalse($report->channels[0]->sourcePopulation->authoritativeComplete);
        $this->assertSame('best_effort', $report->channels[0]->sourcePopulation->snapshotConsistency);
    }

    public function test_empty_verified_population_is_complete_with_zero_count(): void
    {
        Http::fake([
            'rdservice.in.test/*' => Http::response($this->spokePage([], complete: true, next: null, total: 0)),
        ]);

        $report = $this->runReport();

        $this->assertSame(0, $report->channels[0]->sourcePopulation->count);
        $this->assertTrue($report->channels[0]->sourcePopulation->complete);
        $this->assertFalse($report->channels[0]->sourcePopulationComplete);
    }

    public function test_authentication_failure_marks_source_incomplete(): void
    {
        Http::fake([
            'rdservice.in.test/*' => Http::response([], 401),
        ]);

        $report = $this->runReport();

        $this->assertFalse($report->channels[0]->sourcePopulation->complete);
        $this->assertFalse($report->channels[0]->sourcePopulationComplete);
        $this->assertStringContainsString('authentication', strtolower((string) $report->channels[0]->sourcePopulation->failureReason));
    }

    public function test_malformed_json_marks_source_incomplete(): void
    {
        Http::fake([
            'rdservice.in.test/*' => Http::response('not-json', 200),
        ]);

        $report = $this->runReport();

        $this->assertFalse($report->channels[0]->sourcePopulation->complete);
        $this->assertSame(0, $report->channels[0]->sourcePopulation->count);
    }

    public function test_repeated_cursor_is_rejected(): void
    {
        Http::fake([
            'rdservice.in.test/*' => Http::sequence()
                ->push($this->spokePage(['RD9201'], complete: false, next: 'same-cursor'))
                ->push($this->spokePage(['RD9202'], complete: false, next: 'same-cursor')),
        ]);

        $report = $this->runReport();

        $this->assertFalse($report->channels[0]->sourcePopulation->complete);
        $this->assertStringContainsString('repeated cursor', strtolower((string) $report->channels[0]->sourcePopulation->failureReason));
    }

    public function test_paid_completed_spoke_obligation_without_desk_commerce_is_missing_commerce(): void
    {
        Http::fake([
            'rdservice.in.test/*' => Http::response($this->spokePage(
                ['RD9301'],
                obligationClasses: ['paid_completed_service'],
            )),
        ]);

        $report = $this->runReport();

        $this->assertSame(1, $report->channels[0]->counts[StatutoryCoverageClassification::MissingCommerce->value] ?? 0);
    }

    public function test_unpaid_spoke_obligation_classifies_as_non_obligating(): void
    {
        Http::fake([
            'rdservice.in.test/*' => Http::response($this->spokePage(
                ['RD9302'],
                obligationClasses: ['unpaid_or_incomplete'],
            )),
        ]);

        $report = $this->runReport();

        $this->assertSame(1, $report->channels[0]->counts[StatutoryCoverageClassification::SourceNonObligating->value] ?? 0);
    }

    public function test_mismatched_channel_in_spoke_row_is_ignored(): void
    {
        Http::fake([
            'rdservice.in.test/*' => Http::response([
                'complete' => true,
                'population' => ['snapshot_consistency' => 'best_effort', 'total_count' => 1],
                'data' => [[
                    'source_id' => 'RD9303',
                    'source_type' => 'commerce_order',
                    'channel' => 'radiumbox_com',
                    'obligation_class' => 'paid_completed_service',
                ]],
            ]),
        ]);

        $report = $this->runReport();

        $this->assertSame(0, $report->channels[0]->sourcePopulation->count);
    }

    public function test_duplicate_source_identity_in_spoke_response_fails_closed(): void
    {
        Http::fake([
            'rdservice.in.test/*' => Http::response([
                'complete' => true,
                'population' => ['snapshot_consistency' => 'best_effort'],
                'data' => [
                    [
                        'source_id' => 'RD9304',
                        'source_type' => 'commerce_order',
                        'channel' => 'rdservice_in',
                        'obligation_class' => 'paid_completed_service',
                    ],
                    [
                        'source_id' => 'RD9304',
                        'source_type' => 'commerce_order',
                        'channel' => 'rdservice_in',
                        'obligation_class' => 'unpaid_or_incomplete',
                    ],
                ],
            ]),
        ]);

        $report = $this->runReport();

        $this->assertFalse($report->channels[0]->sourcePopulation->complete);
        $this->assertStringContainsString('duplicate source identity', strtolower((string) $report->channels[0]->sourcePopulation->failureReason));
    }

    public function test_http_non_success_marks_source_incomplete_without_zeroing_desk_rows(): void
    {
        $this->seedPaidSupportOrder('RD9401');

        Http::fake([
            'rdservice.in.test/*' => Http::response([], 503),
        ]);

        $report = $this->runReport();

        $this->assertFalse($report->channels[0]->sourcePopulation->complete);
        $this->assertSame(0, $report->channels[0]->sourcePopulation->count);
        $this->assertGreaterThanOrEqual(1, $report->channels[0]->deskPopulationCount);
    }

    public function test_page_limit_exhaustion_marks_pagination_incomplete(): void
    {
        config(['statutory_coverage.obligations.max_pages_per_fetch' => 1]);

        Http::fake([
            'rdservice.in.test/*' => Http::response($this->spokePage(['RD9402'], complete: false, next: 'more-pages')),
        ]);

        $report = $this->runReport();

        $this->assertFalse($report->channels[0]->sourcePopulation->complete);
        $this->assertStringContainsString('max page limit', strtolower((string) $report->channels[0]->sourcePopulation->failureReason));
    }

    public function test_complete_false_without_next_cursor_marks_incomplete(): void
    {
        Http::fake([
            'rdservice.in.test/*' => Http::response([
                'complete' => false,
                'population' => ['snapshot_consistency' => 'best_effort'],
                'data' => [],
            ]),
        ]);

        $report = $this->runReport();

        $this->assertFalse($report->channels[0]->sourcePopulation->complete);
        $this->assertStringContainsString('next_cursor', strtolower((string) $report->channels[0]->sourcePopulation->failureReason));
    }

    public function test_missing_endpoint_path_is_unsupported_not_empty_population(): void
    {
        config(['statutory_coverage.channels.rdservice_in.obligation_list_path' => null]);

        Http::fake();

        $report = $this->runReport();

        $this->assertFalse($report->channels[0]->sourcePopulation->supported);
        $this->assertFalse($report->channels[0]->sourcePopulation->complete);
        Http::assertNothingSent();
    }

    private function seedPaidSupportOrder(string $orderId): void
    {
        $actor = User::factory()->create(['is_active' => true]);

        Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => '7881953',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'cashfree_payment_id' => 'cf_'.$orderId,
            'transaction_id' => 'TXN-'.$orderId,
            'payment_date' => '2026-10-10 10:00:00',
            'completed_at' => now(),
            'status' => 'active',
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }

    /**
     * @param  list<string>  $sourceIds
     * @param  list<string>|null  $obligationClasses
     * @return array<string, mixed>
     */
    private function spokePage(
        array $sourceIds,
        bool $complete = true,
        ?string $next = null,
        ?int $total = null,
        ?array $obligationClasses = null,
    ): array {
        $data = [];
        foreach ($sourceIds as $index => $sourceId) {
            $data[] = [
                'source_id' => $sourceId,
                'source_type' => 'commerce_order',
                'channel' => 'rdservice_in',
                'obligation_class' => $obligationClasses[$index] ?? 'paid_completed_service',
            ];
        }

        return [
            'status' => 200,
            'complete' => $complete,
            'next_cursor' => $next,
            'population' => [
                'total_count' => $total ?? count($sourceIds),
                'snapshot_consistency' => 'best_effort',
            ],
            'data' => $data,
        ];
    }

    private function runReport(): StatutoryCoverageReport
    {
        $service = app(StatutoryCoverageReconciliationService::class);
        $window = new StatutoryCoverageWindow(
            from: Carbon::parse('2026-10-01 00:00:00', 'Asia/Kolkata'),
            to: Carbon::parse('2026-10-31 23:59:59', 'Asia/Kolkata'),
            timezone: 'Asia/Kolkata',
        );

        return $service->reconcile(['rdservice_in'], $window, 1, 500, false, 50);
    }
}
