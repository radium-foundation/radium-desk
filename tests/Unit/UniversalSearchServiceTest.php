<?php

namespace Tests\Unit;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\UniversalSearchService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UniversalSearchServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * @param  array<string, mixed>  $orderAttributes
     * @param  array<string, mixed>  $incidentAttributes
     */
    private function createServiceCase(User $user, array $orderAttributes = [], array $incidentAttributes = []): Incident
    {
        $order = Order::query()->create([
            'order_id' => $orderAttributes['order_id'] ?? 'RD-'.uniqid(),
            'serial_number' => $orderAttributes['serial_number'] ?? 'SN-'.uniqid(),
            'product_name' => $orderAttributes['product_name'] ?? 'MFS 110',
            'device_model' => $orderAttributes['device_model'] ?? 'MFS 110',
            'transaction_id' => $orderAttributes['transaction_id'] ?? null,
            'customer_name' => $orderAttributes['customer_name'] ?? null,
            'customer_email' => $orderAttributes['customer_email'] ?? null,
            'customer_phone' => $orderAttributes['customer_phone'] ?? null,
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => $incidentAttributes['reference_no'] ?? app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Priority test case',
            'description' => 'Priority test case.',
            'status' => IncidentStatus::Open,
            'created_by' => $user->id,
            'assigned_to_user_id' => $user->id,
        ]);
    }

    public function test_exact_match_ranks_before_prefix_match(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $prefixMatch = $this->createServiceCase($user, [
            'order_id' => 'RD3434509-ALT',
        ]);

        $exactMatch = $this->createServiceCase($user, [
            'order_id' => 'RD3434509',
        ]);

        $results = app(UniversalSearchService::class)->search($user, 'RD3434509');

        $this->assertSame(
            [$exactMatch->id, $prefixMatch->id],
            $results->pluck('id')->all(),
        );
    }

    public function test_prefix_match_ranks_before_contains_match(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $containsMatch = $this->createServiceCase($user, [
            'order_id' => 'RD-OTHER-001',
            'serial_number' => 'ZZ-RD3434509-ZZ',
        ]);

        $prefixMatch = $this->createServiceCase($user, [
            'order_id' => 'RD3434509-ALT',
        ]);

        $results = app(UniversalSearchService::class)->search($user, 'RD3434509');

        $this->assertSame(
            [$prefixMatch->id, $containsMatch->id],
            $results->pluck('id')->all(),
        );
    }

    public function test_search_returns_empty_collection_for_blank_query(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $results = app(UniversalSearchService::class)->search($user, '   ');

        $this->assertTrue($results->isEmpty());
    }

    public function test_search_returns_at_most_twenty_results(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        for ($index = 1; $index <= 25; $index++) {
            $this->createServiceCase($user, [
                'order_id' => 'RD-LIMIT-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                'customer_phone' => '7700000'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
            ]);
        }

        $results = app(UniversalSearchService::class)->search($user, '7700000');

        $this->assertCount(UniversalSearchService::RESULT_LIMIT, $results);
    }

    public function test_progressive_device_model_search_matches_each_token(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $fm220 = $this->createServiceCase($user, [
            'device_model' => 'FM 220',
            'product_name' => 'FM 220',
        ], [
            'reference_no' => 'SC-FM-220',
        ]);

        $fm200 = $this->createServiceCase($user, [
            'device_model' => 'FM200',
            'product_name' => 'FM200',
        ], [
            'reference_no' => 'SC-FM-200',
        ]);

        $service = app(UniversalSearchService::class);

        $fmResults = $service->search($user, 'fm')->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$fm220->id, $fm200->id], $fmResults);

        $fmTwoResults = $service->search($user, 'fm 2')->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$fm220->id, $fm200->id], $fmTwoResults);

        $fmTwentyTwoResults = $service->search($user, 'fm 22')->pluck('id')->all();
        $this->assertSame([$fm220->id], $fmTwentyTwoResults);
    }

    public function test_exact_order_identifier_fast_path_finds_exact_order_case(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incident = $this->createServiceCase($user, [
            'order_id' => 'RD3437143',
        ], [
            'status' => IncidentStatus::Closed,
        ]);

        $results = app(UniversalSearchService::class)->search($user, 'RD3437143');

        $this->assertSame([$incident->id], $results->pluck('id')->all());
    }

    public function test_exact_order_identifier_fast_path_returns_empty_for_unknown_order(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $results = app(UniversalSearchService::class)->search($user, 'RD9999999999');

        $this->assertTrue($results->isEmpty());
    }

    public function test_partial_order_fragment_uses_broad_search_not_fast_path(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $match = $this->createServiceCase($user, [
            'order_id' => 'RD3437999',
        ]);

        $results = app(UniversalSearchService::class)->search($user, 'RD3437');

        $this->assertContains($match->id, $results->pluck('id')->all());
    }

    public function test_exact_order_fast_path_returns_multiple_incidents_for_same_order(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-MULTI-001',
            'serial_number' => 'SN-MULTI',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $first = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'First',
            'description' => 'First',
            'status' => IncidentStatus::Open,
            'created_by' => $user->id,
            'assigned_to_user_id' => $user->id,
        ]);

        $second = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Second',
            'description' => 'Second',
            'status' => IncidentStatus::Closed,
            'created_by' => $user->id,
            'assigned_to_user_id' => $user->id,
            'updated_at' => now()->subMinute(),
        ]);

        $results = app(UniversalSearchService::class)->search($user, 'RD-MULTI-001');

        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id],
            $results->pluck('id')->all(),
        );
    }

    public function test_exact_order_fast_path_deduplicates_incidents(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incident = $this->createServiceCase($user, [
            'order_id' => 'RD-DEDUP-001',
        ]);

        $results = app(UniversalSearchService::class)->search($user, 'RD-DEDUP-001');

        $this->assertCount(1, $results);
        $this->assertSame($incident->id, $results->first()->id);
    }

    public function test_exact_order_identifier_fast_path_uses_indexed_order_lookup(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        for ($index = 1; $index <= 30; $index++) {
            $this->createServiceCase($user, [
                'order_id' => 'RD-NOISE-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
            ]);
        }

        $target = $this->createServiceCase($user, [
            'order_id' => 'RD3437143',
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $results = app(UniversalSearchService::class)->search($user, 'RD3437143');
        $queryLog = DB::getQueryLog();

        $this->assertSame([$target->id], $results->pluck('id')->all());
        $this->assertNotEmpty($queryLog);

        $sql = implode("\n", array_map(
            fn (array $entry): string => (string) ($entry['query'] ?? $entry['sql'] ?? ''),
            $queryLog,
        ));

        $this->assertStringContainsString('order_id', $sql);
        $this->assertTrue(
            str_contains($sql, 'order_id =')
            || str_contains($sql, '"order_id" =')
            || str_contains($sql, '`order_id` ='),
            'Expected indexed equality predicate on orders.order_id',
        );
    }
}
