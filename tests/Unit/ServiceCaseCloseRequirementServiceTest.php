<?php

namespace Tests\Unit;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\ServiceCaseCloseRequirementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceCaseCloseRequirementServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_inquiry_order_does_not_require_serial_number(): void
    {
        $incident = $this->createIncident(
            orderId: 'INQ-SC08777',
            serialNumber: null,
        );

        $messages = app(ServiceCaseCloseRequirementService::class)->validate(
            incident: $incident,
            serialNumberUnavailable: false,
            referenceNumberUnavailable: false,
        );

        $this->assertArrayNotHasKey('serial_number', $messages);
        $this->assertSame([], $messages);
    }

    public function test_device_order_requires_serial_number(): void
    {
        $incident = $this->createIncident(
            orderId: 'RD-DEVICE-1',
            serialNumber: null,
        );

        $messages = app(ServiceCaseCloseRequirementService::class)->validate(
            incident: $incident,
            serialNumberUnavailable: false,
            referenceNumberUnavailable: false,
        );

        $this->assertSame(
            'Serial Number is required before closing this service case.',
            $messages['serial_number'] ?? null,
        );
    }

    public function test_serial_unavailable_exception_still_bypasses_serial_for_device_orders(): void
    {
        $incident = $this->createIncident(
            orderId: 'RD-DEVICE-2',
            serialNumber: null,
        );

        $messages = app(ServiceCaseCloseRequirementService::class)->validate(
            incident: $incident,
            serialNumberUnavailable: true,
            referenceNumberUnavailable: false,
        );

        $this->assertArrayNotHasKey('serial_number', $messages);
    }

    private function createIncident(string $orderId, ?string $serialNumber): Incident
    {
        $creator = User::factory()->create();

        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => $serialNumber,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Close requirement test',
            'description' => 'Close requirement test description.',
            'status' => IncidentStatus::Open,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ])->load('order');
    }
}
