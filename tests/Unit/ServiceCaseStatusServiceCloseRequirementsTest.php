<?php

namespace Tests\Unit;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\Remark;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\ServiceCaseStatusService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ServiceCaseStatusServiceCloseRequirementsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_agent_can_close_remote_support_case_without_transaction_id(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incident = $this->createIncident(
            orderId: 'CFPay_techsupport_close_001',
            cashfreePaymentId: 'cf_pay_close_001',
        );

        Remark::query()->create([
            'user_id' => $agent->id,
            'remarkable_type' => $incident->getMorphClass(),
            'remarkable_id' => $incident->id,
            'body' => 'Remote support completed.',
        ]);

        app(ServiceCaseStatusService::class)->updateStatus(
            incident: $incident,
            status: IncidentStatus::Closed,
            actor: $agent,
        );

        $this->assertSame(IncidentStatus::Closed, $incident->fresh()->status);
    }

    public function test_agent_still_requires_transaction_id_for_hardware_case(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incident = $this->createIncident(
            orderId: 'RD-DEVICE-CLOSE-001',
            cashfreePaymentId: null,
            transactionId: null,
        );

        Remark::query()->create([
            'user_id' => $agent->id,
            'remarkable_type' => $incident->getMorphClass(),
            'remarkable_id' => $incident->id,
            'body' => 'Attempting hardware close.',
        ]);

        $this->expectException(ValidationException::class);

        try {
            app(ServiceCaseStatusService::class)->updateStatus(
                incident: $incident,
                status: IncidentStatus::Closed,
                actor: $agent,
            );
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Assign a transaction ID to the related order before closing this service case.',
                $exception->errors()['transaction_id'][0] ?? null,
            );

            throw $exception;
        }
    }

    private function createIncident(
        string $orderId,
        ?string $cashfreePaymentId,
        ?string $transactionId = null,
    ): Incident {
        $creator = User::factory()->create();

        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => '10137886',
            'product_name' => null,
            'device_model' => null,
            'cashfree_payment_id' => $cashfreePaymentId,
            'transaction_id' => $transactionId,
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
