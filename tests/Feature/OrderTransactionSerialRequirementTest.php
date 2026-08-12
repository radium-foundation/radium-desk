<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\DeviceModel;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderTransactionService;
use Database\Seeders\DeviceModelSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrderTransactionSerialRequirementTest extends TestCase
{
    use RefreshDatabase;

    private const SERIAL_ERROR = 'Service Reference Number cannot be assigned until a valid Serial Number is available.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DeviceModelSeeder::class);
    }

    private function createAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $admin;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createOrder(User $creator, array $overrides = []): Order
    {
        return Order::query()->create([
            'order_id' => $overrides['order_id'] ?? 'RD-SERIAL-REQ-001',
            'serial_number' => $overrides['serial_number'] ?? '7881953',
            'product_name' => $overrides['product_name'] ?? 'MFS 110',
            'device_model' => $overrides['device_model'] ?? 'MFS 110',
            'cashfree_payment_id' => $overrides['cashfree_payment_id'] ?? 'cf_serial_req_001',
            'status' => 'active',
            'created_by' => $creator->id,
            ...$overrides,
        ]);
    }

    private function verifyLegacyImportFulfillment(User $admin, Order $order): void
    {
        $this->actingAs($admin)
            ->postJson(route('orders.legacy-verification.store', $order), [
                'confirmed' => true,
            ])
            ->assertOk();
    }

    #[DataProvider('invalidSerialProvider')]
    public function test_assign_transaction_id_rejects_invalid_serial(?string $serialNumber): void
    {
        $admin = $this->createAdmin();
        $order = $this->createOrder($admin, [
            'order_id' => 'RD-SERIAL-INVALID-'.md5((string) $serialNumber),
            'serial_number' => $serialNumber,
        ]);

        $this->actingAs($admin)
            ->post(route('orders.transaction.store', $order), [
                'transaction_id' => 'TXN-INVALID-SERIAL',
            ])
            ->assertSessionHasErrors([
                'transaction_id' => self::SERIAL_ERROR,
            ]);

        $this->assertNull($order->fresh()->transaction_id);
    }

    /**
     * @return array<string, array{0: ?string}>
     */
    public static function invalidSerialProvider(): array
    {
        return [
            'null serial' => [null],
            'empty serial' => [''],
            'whitespace serial' => ['   '],
            'placeholder unknown' => ['UNKNOWN'],
        ];
    }

    public function test_assign_transaction_id_succeeds_with_valid_serial(): void
    {
        $admin = $this->createAdmin();
        $order = $this->createOrder($admin, [
            'order_id' => 'RD-SERIAL-VALID-001',
            'serial_number' => '7881953',
        ]);

        $this->actingAs($admin)
            ->post(route('orders.transaction.store', $order), [
                'transaction_id' => 'TXN-VALID-SERIAL',
            ])
            ->assertRedirect(route('orders.show', $order));

        $this->assertSame('TXN-VALID-SERIAL', $order->fresh()->transaction_id);
    }

    public function test_legacy_imported_order_without_serial_cannot_receive_service_reference_after_verification(): void
    {
        $admin = $this->createAdmin();
        $order = Order::query()->create([
            'order_id' => 'RD3430643',
            'serial_number' => null,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'legacy_source' => 'radiumbox',
            'legacy_imported_at' => now(),
            'legacy_imported_by_user_id' => $admin->id,
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $this->verifyLegacyImportFulfillment($admin, $order);

        $this->actingAs($admin)
            ->post(route('orders.transaction.store', $order), [
                'transaction_id' => 'TXN-LEGACY-NO-SERIAL',
            ])
            ->assertSessionHasErrors([
                'transaction_id' => self::SERIAL_ERROR,
            ]);

        $this->assertNull($order->fresh()->transaction_id);
    }

    public function test_bulk_assignment_fails_orders_without_serial_and_succeeds_for_valid_serial(): void
    {
        $admin = $this->createAdmin();

        $validOrder = $this->createOrder($admin, [
            'order_id' => 'RD-BULK-SERIAL-VALID',
            'serial_number' => '7881954',
            'cashfree_payment_id' => 'cf_bulk_serial_valid',
        ]);

        $invalidOrder = $this->createOrder($admin, [
            'order_id' => 'RD-BULK-SERIAL-INVALID',
            'serial_number' => null,
            'cashfree_payment_id' => 'cf_bulk_serial_invalid',
        ]);

        $validIncident = Incident::query()->create([
            'order_id' => $validOrder->id,
            'reference_no' => 'SC-BULK-SERIAL-VALID',
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Valid serial bulk case',
            'description' => 'Valid serial bulk case.',
            'status' => IncidentStatus::Open->value,
            'created_by' => $admin->id,
        ]);

        $invalidIncident = Incident::query()->create([
            'order_id' => $invalidOrder->id,
            'reference_no' => 'SC-BULK-SERIAL-INVALID',
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Invalid serial bulk case',
            'description' => 'Invalid serial bulk case.',
            'status' => IncidentStatus::Open->value,
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)
            ->postJson(route('dashboard.transactions.bulk'), [
                'incident_ids' => [$validIncident->id, $invalidIncident->id],
                'transaction_id' => 'TXN-BULK-SERIAL',
            ])
            ->assertOk()
            ->assertJsonPath('count', 1);

        $this->assertSame('TXN-BULK-SERIAL', $validOrder->fresh()->transaction_id);
        $this->assertNull($invalidOrder->fresh()->transaction_id);

        $failedIncidents = collect($response->json('failed_incidents'));
        $this->assertTrue($failedIncidents->contains(
            fn (array $failure): bool => $failure['incident_id'] === $invalidIncident->id
                && $failure['message'] === self::SERIAL_ERROR,
        ));
    }

    public function test_direct_order_create_cannot_supply_transaction_id(): void
    {
        $admin = $this->createAdmin();
        $deviceModel = DeviceModel::query()->firstOrFail();

        $this->actingAs($admin)
            ->post(route('orders.store'), [
                'order_id' => 'RD-CREATE-BYPASS-001',
                'serial_number' => '7881955',
                'product_name' => 'MFS 110',
                'device_model_id' => $deviceModel->id,
                'transaction_id' => 'TXN-CREATE-BYPASS',
            ])
            ->assertSessionHasErrors('transaction_id');

        $this->assertDatabaseMissing('orders', [
            'order_id' => 'RD-CREATE-BYPASS-001',
        ]);
    }

    public function test_remote_support_order_without_serial_cannot_receive_service_reference(): void
    {
        $admin = $this->createAdmin();
        $order = Order::query()->create([
            'order_id' => 'CFPay_techsupport_serial_req',
            'serial_number' => null,
            'product_name' => null,
            'device_model' => null,
            'cashfree_payment_id' => 'cf_pay_remote_support_serial_req',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $this->assertTrue($order->isRemoteSupportOrder());

        $this->actingAs($admin)
            ->post(route('orders.transaction.store', $order), [
                'transaction_id' => 'TXN-REMOTE-NO-SERIAL',
            ])
            ->assertSessionHasErrors([
                'transaction_id' => self::SERIAL_ERROR,
            ]);

        $this->assertNull($order->fresh()->transaction_id);
    }

    public function test_service_layer_rejects_invalid_serial_before_other_gates(): void
    {
        $admin = $this->createAdmin();
        $order = $this->createOrder($admin, [
            'order_id' => 'RD-SERVICE-LAYER-001',
            'serial_number' => null,
            'cashfree_payment_id' => null,
        ]);

        try {
            app(OrderTransactionService::class)->assignTransactionId(
                order: $order,
                transactionId: 'TXN-SERVICE-LAYER',
                actor: $admin,
                broadcast: false,
            );

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                self::SERIAL_ERROR,
                $exception->errors()['transaction_id'][0] ?? null,
            );
        }
    }
}
