<?php

namespace Tests\Feature\Services;

use App\Data\CustomerCorrectionData;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\User;
use App\Services\CustomerCorrectionService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CustomerCorrectionServiceTest extends TestCase
{
    use RefreshDatabase;

    private CustomerCorrectionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->service = app(CustomerCorrectionService::class);
    }

    #[Test]
    public function it_applies_a_single_customer_field_correction(): void
    {
        $actor = User::factory()->create();
        $actor->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-CUSTOMER-CORRECT',
            'customer_name' => 'Old Name',
            'customer_phone' => '9876543210',
            'customer_email' => 'old@example.com',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        $updatedOrder = $this->service->apply(
            $order,
            new CustomerCorrectionData(
                customerName: 'New Name',
                customerPhone: '9876543210',
                customerEmail: 'old@example.com',
                reason: 'Customer confirmed the correct spelling.',
            ),
            $actor,
        );

        $this->assertSame('New Name', $updatedOrder->customer_name);
        $this->assertSame('9876543210', $updatedOrder->customer_phone);
        $this->assertSame('old@example.com', $updatedOrder->customer_email);
        $this->assertSame($actor->id, $updatedOrder->updated_by);
        $this->assertTrue($updatedOrder->isCustomerNameLocked());
        $this->assertSame($actor->id, $updatedOrder->customer_name_locked_by);
        $this->assertNotNull($updatedOrder->customer_name_locked_at);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'customer_name' => 'New Name',
            'updated_by' => $actor->id,
        ]);

        $this->assertDatabaseCount('customer_data_corrections', 1);
        $this->assertDatabaseHas('customer_data_corrections', [
            'order_id' => $order->id,
            'corrected_by' => $actor->id,
            'status' => 'applied',
            'reason' => 'Customer confirmed the correct spelling.',
        ]);

        $this->assertDatabaseCount('customer_data_correction_items', 1);
        $this->assertDatabaseHas('customer_data_correction_items', [
            'field_name' => 'customer_name',
            'old_value' => 'Old Name',
            'new_value' => 'New Name',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'customer.details.corrected',
            'auditable_type' => $order->getMorphClass(),
            'auditable_id' => $order->id,
            'user_id' => $actor->id,
        ]);

        $auditLog = AuditLog::query()
            ->where('event', 'customer.details.corrected')
            ->where('auditable_id', $order->id)
            ->first();

        $this->assertNotNull($auditLog);
        $this->assertSame('Old Name', $auditLog->old_values['customer_name']);
        $this->assertSame('New Name', $auditLog->new_values['customer_name']);
    }

    #[Test]
    public function it_rejects_when_nothing_changed(): void
    {
        $actor = User::factory()->create();
        $actor->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-CUSTOMER-UNCHANGED',
            'customer_name' => 'Same Name',
            'customer_phone' => '9876543210',
            'customer_email' => 'same@example.com',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        try {
            $this->service->apply(
                $order,
                new CustomerCorrectionData(
                    customerName: 'Same Name',
                    customerPhone: '9876543210',
                    customerEmail: 'same@example.com',
                    reason: 'No actual change.',
                ),
                $actor,
            );
            $this->fail('Expected ValidationException to be thrown.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertDatabaseCount('customer_data_corrections', 0);
        $this->assertDatabaseCount('customer_data_correction_items', 0);
    }
}
