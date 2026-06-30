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
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
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
}
