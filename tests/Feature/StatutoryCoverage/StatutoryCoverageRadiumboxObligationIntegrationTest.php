<?php

namespace Tests\Feature\StatutoryCoverage;

use App\Data\StatutoryCoverage\StatutoryCoverageReport;
use App\Data\StatutoryCoverage\StatutoryCoverageWindow;
use App\Enums\StatutoryCoverage\StatutoryCoverageClassification;
use App\Services\StatutoryCoverage\StatutoryCoverageReconciliationService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StatutoryCoverageRadiumboxObligationIntegrationTest extends TestCase
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
            'order_lookup.spokes.radiumbox_com.enabled' => true,
            'order_lookup.spokes.radiumbox_com.base_url' => 'https://radiumbox.test',
            'order_lookup.spokes.radiumbox_com.token' => 'test-token',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_radiumbox_obligation_path_is_configured(): void
    {
        $this->assertSame(
            '/api/integrations/v1/statutory-obligations',
            config('statutory_coverage.channels.radiumbox_com.obligation_list_path'),
        );
        $this->assertSame('radiumbox_com', config('statutory_coverage.channels.radiumbox_com.spoke'));
    }

    public function test_paid_completed_hardware_without_desk_commerce_is_missing_commerce(): void
    {
        Http::fake([
            'radiumbox.test/*' => Http::response($this->spokePage(
                ['RBP9001'],
                obligationClasses: ['paid_completed_hardware'],
            )),
        ]);

        $report = $this->runReport();

        $this->assertSame('radiumbox_com', $report->channels[0]->channel);
        $this->assertSame(1, $report->channels[0]->counts[StatutoryCoverageClassification::MissingCommerce->value] ?? 0);
    }

    public function test_paid_completed_service_on_radiumbox_remains_missing_commerce_when_no_commerce(): void
    {
        Http::fake([
            'radiumbox.test/*' => Http::response($this->spokePage(
                ['RB9001'],
                obligationClasses: ['paid_completed_service'],
            )),
        ]);

        $report = $this->runReport();

        $this->assertSame(1, $report->channels[0]->counts[StatutoryCoverageClassification::MissingCommerce->value] ?? 0);
    }

    public function test_ineligible_statutory_remains_non_obligating(): void
    {
        Http::fake([
            'radiumbox.test/*' => Http::response($this->spokePage(
                ['RBP9002'],
                obligationClasses: ['ineligible_statutory'],
            )),
        ]);

        $report = $this->runReport();

        $this->assertSame(1, $report->channels[0]->counts[StatutoryCoverageClassification::SourceNonObligating->value] ?? 0);
    }

    public function test_wrong_channel_rows_are_not_ingested_for_radiumbox_reconcile(): void
    {
        Http::fake([
            'radiumbox.test/*' => Http::response([
                'complete' => true,
                'population' => ['snapshot_consistency' => 'best_effort'],
                'data' => [[
                    'source_id' => 'RD9001',
                    'source_type' => 'commerce_order',
                    'channel' => 'rdservice_in',
                    'obligation_class' => 'paid_completed_service',
                ]],
            ]),
        ]);

        $report = $this->runReport();

        $this->assertSame(0, $report->channels[0]->sourcePopulation->count);
        $this->assertSame(0, $report->channels[0]->counts[StatutoryCoverageClassification::MissingCommerce->value] ?? 0);
    }

    public function test_best_effort_population_is_not_authoritatively_complete(): void
    {
        Http::fake([
            'radiumbox.test/*' => Http::response($this->spokePage(['RBP9003'], obligationClasses: ['paid_completed_hardware'])),
        ]);

        $report = $this->runReport();

        $this->assertTrue($report->channels[0]->sourcePopulation->complete);
        $this->assertFalse($report->channels[0]->sourcePopulation->authoritativeComplete);
        $this->assertFalse($report->channels[0]->sourcePopulationComplete);
        $this->assertFalse($report->channels[0]->reportComplete);
    }

    public function test_authentication_failure_fails_closed(): void
    {
        Http::fake([
            'radiumbox.test/*' => Http::response([], 401),
        ]);

        $report = $this->runReport();

        $this->assertFalse($report->channels[0]->sourcePopulation->complete);
        $this->assertFalse($report->channels[0]->sourcePopulationComplete);
        $this->assertSame(0, $report->channels[0]->sourcePopulation->count);
    }

    public function test_reconcile_does_not_issue_http_mutations(): void
    {
        Http::fake([
            'radiumbox.test/*' => Http::response($this->spokePage(['RBP9004'], obligationClasses: ['paid_completed_hardware'])),
        ]);

        $this->runReport();

        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            if ($request->method() !== 'GET') {
                return false;
            }

            $url = $request->url();
            if (! str_contains($url, 'radiumbox.test/api/integrations/v1/statutory-obligations')) {
                return false;
            }

            if ($request->header('Authorization') !== ['Bearer test-token']) {
                return false;
            }

            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            return isset($query['from'], $query['to'], $query['timezone'], $query['per_page']);
        });
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
        ?array $obligationClasses = null,
    ): array {
        $data = [];
        foreach ($sourceIds as $index => $sourceId) {
            $data[] = [
                'source_id' => $sourceId,
                'source_type' => 'commerce_order',
                'channel' => 'radiumbox_com',
                'obligation_class' => $obligationClasses[$index] ?? 'paid_completed_hardware',
                'desk_submission' => null,
                'invoice_sync' => null,
            ];
        }

        return [
            'complete' => $complete,
            'next_cursor' => $next,
            'population' => [
                'snapshot_consistency' => 'best_effort',
                'total_count' => count($sourceIds),
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

        return $service->reconcile(['radiumbox_com'], $window, 1, 500, false, 50);
    }
}
