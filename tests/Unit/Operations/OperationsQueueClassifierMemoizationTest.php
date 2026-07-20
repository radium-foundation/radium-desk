<?php

namespace Tests\Unit\Operations;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\Operations\OperationsQueueClassifier;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationsQueueClassifierMemoizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_remember_classifications_computes_once_per_incident(): void
    {
        $incident = $this->createOpenIncident('RB-MEMO-1');
        $classifier = app(OperationsQueueClassifier::class)->rememberClassifications();

        $first = $classifier->classify($incident);
        $second = $classifier->classify($incident->fresh(['order', 'activeWaitingState', 'activeBusinessHold', 'supportAppointments']));

        $this->assertSame($first, $second);
        $this->assertSame(1, $classifier->classificationComputeCount());
    }

    public function test_classification_memo_is_disabled_by_default(): void
    {
        $incident = $this->createOpenIncident('RB-MEMO-2');
        $classifier = app(OperationsQueueClassifier::class);

        $classifier->classify($incident);
        $classifier->classify($incident);

        $this->assertSame(2, $classifier->classificationComputeCount());
    }

    private function createOpenIncident(string $orderId): Incident
    {
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => null,
            'product_name' => 'MFS110',
            'device_model' => 'MFS110',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Memo classification',
            'description' => 'Memo classification',
            'status' => IncidentStatus::Open,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);
    }
}
