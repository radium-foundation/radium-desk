<?php

namespace Tests\Unit;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\DashboardUniversalSearchService;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardUniversalSearchServiceTest extends TestCase
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

    public function test_mobile_match_ranks_before_customer_name_match(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $phoneMatch = $this->createServiceCase($user, [
            'order_id' => 'RD-PHONE-001',
            'customer_phone' => '9876543210',
            'customer_name' => 'Other Person',
        ]);

        $nameMatch = $this->createServiceCase($user, [
            'order_id' => 'RD-NAME-001',
            'customer_name' => '9876543210 Person',
        ]);

        $results = app(DashboardUniversalSearchService::class)->search($user, '9876543210');

        $this->assertSame(
            [$phoneMatch->id, $nameMatch->id],
            $results->pluck('id')->all(),
        );
    }

    public function test_order_id_match_ranks_before_serial_number_match(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $orderMatch = $this->createServiceCase($user, [
            'order_id' => 'RD3434509',
            'serial_number' => 'SN-001',
        ]);

        $serialMatch = $this->createServiceCase($user, [
            'order_id' => 'RD-OTHER-001',
            'serial_number' => 'RD3434509',
        ]);

        $results = app(DashboardUniversalSearchService::class)->search($user, 'RD3434509');

        $this->assertSame(
            [$orderMatch->id, $serialMatch->id],
            $results->pluck('id')->all(),
        );
    }

    public function test_search_returns_empty_collection_for_blank_query(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $results = app(DashboardUniversalSearchService::class)->search($user, '   ');

        $this->assertTrue($results->isEmpty());
    }
}
