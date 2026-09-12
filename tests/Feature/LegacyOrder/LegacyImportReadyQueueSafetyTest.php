<?php

namespace Tests\Feature\LegacyOrder;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\OperationQueue;
use App\Models\DeviceModel;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\CustomerVerificationService;
use App\Services\Operations\OperationsQueueClassifier;
use App\Services\ServiceCaseAssignmentEligibilityService;
use Database\Seeders\DeviceModelSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LegacyImportReadyQueueSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DeviceModelSeeder::class);
        $this->seed(SettingsSeeder::class);

        config([
            'radiumbox.enabled' => true,
            'radiumbox.base_url' => 'https://admin.radiumbox.com',
            'radiumbox.admin_fallback_enabled' => true,
            'service_case_assignment.automation_grace_period_enabled' => false,
        ]);
    }

    public function test_legacy_import_does_not_enter_ready_queue_before_fulfillment_verification(): void
    {
        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response($this->legacyOrderApiResponse()),
        ]);

        $agent = User::factory()->create(['name' => 'Legacy Import Agent']);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->postJson(route('service-requests.quick.store'), [
                'action' => 'legacy_import',
                'legacy_order_id' => 'RD3395988',
                'source' => IncidentSource::Call->value,
                'notes' => 'Imported legacy order for Ready Queue safety test.',
            ])
            ->assertOk();

        $order = Order::query()->where('order_id', 'RD3395988')->firstOrFail();
        $incident = Incident::query()->where('order_id', $order->id)->firstOrFail();

        $classifier = app(OperationsQueueClassifier::class);
        $eligibility = app(ServiceCaseAssignmentEligibilityService::class);

        $this->assertTrue($order->isLegacyImported());
        $this->assertFalse(app(CustomerVerificationService::class)->isLegacyImportFulfillmentVerified($order));
        $this->assertFalse($classifier->isReadyForReferenceEntry($incident));
        $this->assertFalse($eligibility->isReadyForReferenceEntry($order, $incident));
        $this->assertNotSame(OperationQueue::ActionRequired, $classifier->classify($incident));
    }

    public function test_current_desk_order_ready_queue_behavior_remains_available(): void
    {
        $deviceModel = DeviceModel::query()->where('name', 'MFS110')->firstOrFail();
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-CURRENT-READY',
            'serial_number' => '7881953',
            'device_model' => $deviceModel->name,
            'product_name' => $deviceModel->name,
            'device_model_id' => $deviceModel->id,
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-CURRENT-READY',
            'category' => 'General',
            'source' => IncidentSource::Cashfree,
            'title' => 'Current Desk ready case',
            'description' => 'Current Desk order ready eligibility.',
            'status' => IncidentStatus::Open,
            'assigned_to_user_id' => null,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $eligibility = app(ServiceCaseAssignmentEligibilityService::class);

        $this->assertFalse($order->isLegacyImported());
        $this->assertTrue($eligibility->passesValidationForOrder($order));
        $this->assertTrue($eligibility->isReadyForReferenceEntry($order, $incident));
    }

    /**
     * @return array<string, mixed>
     */
    private function legacyOrderApiResponse(): array
    {
        return [
            'status' => 200,
            'data' => [
                'rd_order' => [
                    'serial_no' => '7881953',
                    'product_name' => 'MFS110',
                    'customer_name' => 'Satyam Test',
                    'mobile' => '9876543210',
                    'email' => 'test@example.com',
                    'invoice_number' => 'INV-9988',
                    'purchase_year' => '2022',
                    'amc_status' => 'Active',
                    'amc_year' => '2025',
                    'order_status' => 'Completed',
                ],
            ],
        ];
    }
}
