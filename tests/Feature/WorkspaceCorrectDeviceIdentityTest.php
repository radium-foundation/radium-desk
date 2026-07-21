<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\DeviceModel;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use Database\Seeders\DeviceModelSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceCorrectDeviceIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DeviceModelSeeder::class);
        $this->withHeaders(['Sec-Fetch-Site' => 'same-origin']);
    }

    public function test_admin_can_load_correct_device_identity_dialog(): void
    {
        [$admin, $incident] = $this->createIncident(null, RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($admin)
            ->get(route('incidents.components.show', [
                'incident' => $incident,
                'component' => 'correct-device-identity',
                'workspace_context' => 'customer',
            ]))
            ->assertOk()
            ->assertSee('Correct Device Identity')
            ->assertSee('data-correct-device-identity-dialog', false);
    }

    public function test_customer_360_overflow_menu_launches_correct_device_identity_component(): void
    {
        [$admin, $incident] = $this->createIncident('7881953', RolePermissionSeeder::ROLE_ADMIN);

        $html = $this->actingAs($admin)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Correct Device Identity', $html);
        $this->assertStringContainsString('data-workspace-trigger="correct-device-identity"', $html);
        $this->assertStringNotContainsString('data-workspace-trigger="correct-serial-number"', $html);
    }

    public function test_admin_can_correct_device_identity_from_customer360(): void
    {
        [$admin, $incident] = $this->createIncident(null, RolePermissionSeeder::ROLE_ADMIN);
        $replacementModel = DeviceModel::query()->where('name', 'L1')->firstOrFail();

        $this->actingAs($admin)
            ->patchJson(route('incidents.workspace.correct-device-identity', $incident), [
                'device_model_id' => $replacementModel->id,
                'serial_number' => '7881953',
                'reason' => 'Customer confirmed the correct device identity on a verified call.',
                'workspace_context' => 'customer',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $order = $incident->order->fresh();
        $this->assertSame($replacementModel->id, $order->device_model_id);
        $this->assertSame('7881953', $order->serial_number);
    }

    /**
     * @return array{0: User, 1: Incident}
     */
    private function createIncident(?string $serial, string $role): array
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        $deviceModel = DeviceModel::query()->where('name', 'MFS110')->firstOrFail();

        $order = Order::query()->create([
            'order_id' => 'RD-IDENTITY-'.uniqid(),
            'serial_number' => $serial,
            'serial_entered_at' => $serial !== null ? now() : null,
            'serial_entered_by_user_id' => $serial !== null ? $user->id : null,
            'product_name' => 'MFS 110',
            'device_model' => $deviceModel->name,
            'device_model_id' => $deviceModel->id,
            'device_model_assigned_at' => now(),
            'device_model_assigned_by_user_id' => $user->id,
            'cashfree_payment_id' => 'cf_pay_'.uniqid(),
            'customer_name' => 'Identity Customer',
            'customer_email' => 'identity@example.com',
            'customer_phone' => '9123456782',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Device identity correction case',
            'description' => 'Device identity correction case.',
            'status' => IncidentStatus::Open,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'assigned_to_user_id' => $user->id,
        ]);

        return [$user, $incident];
    }
}
