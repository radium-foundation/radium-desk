<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceCorrectSerialNumberTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->withHeaders(['Sec-Fetch-Site' => 'same-origin']);
    }

    public function test_admin_can_load_correct_serial_number_dialog(): void
    {
        [$admin, $incident] = $this->createIncidentWithSerial('7881953');

        $this->actingAs($admin)
            ->get(route('incidents.components.show', [
                'incident' => $incident,
                'component' => 'correct-serial-number',
                'workspace_context' => 'customer',
            ]))
            ->assertOk()
            ->assertSee('Correct Serial Number')
            ->assertSee('7881953')
            ->assertSee('data-correct-serial-number-dialog', false);
    }

    public function test_agent_cannot_load_correct_serial_number_dialog_when_unpaid(): void
    {
        [$agent, $incident] = $this->createIncidentWithSerial('7881953', RolePermissionSeeder::ROLE_AGENT, paid: false);

        $this->actingAs($agent)
            ->get(route('incidents.components.show', [
                'incident' => $incident,
                'component' => 'correct-serial-number',
                'workspace_context' => 'customer',
            ]))
            ->assertForbidden();
    }

    public function test_agent_can_load_correct_serial_number_dialog_on_paid_rd_order(): void
    {
        [$agent, $incident] = $this->createIncidentWithSerial('7881953', RolePermissionSeeder::ROLE_AGENT, paid: true);
        $agent->givePermissionTo(RolePermissionSeeder::PERMISSION_CORRECT_ORDER_IDENTITY);

        $this->actingAs($agent)
            ->get(route('incidents.components.show', [
                'incident' => $incident,
                'component' => 'correct-serial-number',
                'workspace_context' => 'customer',
            ]))
            ->assertOk()
            ->assertSee('Correct Serial Number');
    }

    public function test_live_validation_preview_uses_serial_validation_service(): void
    {
        [$admin, $incident] = $this->createIncidentWithSerial('7881953');

        $this->actingAs($admin)
            ->postJson(route('incidents.workspace.correct-serial-number.validate', $incident), [
                'serial_number' => '7881954',
                'workspace_context' => 'customer',
            ])
            ->assertOk()
            ->assertJsonPath('normalized_serial', '7881954')
            ->assertJsonPath('duplicate', false);
    }

    public function test_admin_can_correct_serial_number_from_customer360(): void
    {
        [$admin, $incident] = $this->createIncidentWithSerial('7881953');

        $this->actingAs($admin)
            ->patchJson(route('incidents.workspace.correct-serial-number', $incident), [
                'serial_number' => '7881954',
                'reason' => 'Customer confirmed the correct serial on a verified call.',
                'workspace_context' => 'customer',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('extensions.refresh_customer360', true);

        $order = $incident->order->fresh();
        $this->assertSame('7881954', $order->serial_number);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'serial.assigned',
            'auditable_type' => $order->getMorphClass(),
            'auditable_id' => $order->id,
            'user_id' => $admin->id,
        ]);
    }

    public function test_correction_rejects_duplicate_serial(): void
    {
        [$admin, $incident] = $this->createIncidentWithSerial('7881954');

        Order::query()->create([
            'order_id' => 'RD-SERIAL-OWNER',
            'serial_number' => '7881953',
            'serial_entered_at' => now(),
            'serial_entered_by_user_id' => $admin->id,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->patchJson(route('incidents.workspace.correct-serial-number', $incident), [
                'serial_number' => '7881953',
                'reason' => 'Attempting to use an existing serial.',
                'workspace_context' => 'customer',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['serial_number']);

        $this->assertSame('7881954', $incident->order->fresh()->serial_number);
    }

    public function test_correction_rejects_unchanged_serial(): void
    {
        [$admin, $incident] = $this->createIncidentWithSerial('7881953');

        $this->actingAs($admin)
            ->patchJson(route('incidents.workspace.correct-serial-number', $incident), [
                'serial_number' => '7881953',
                'reason' => 'No actual change.',
                'workspace_context' => 'customer',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['serial_number']);
    }

    /**
     * @return array{0: User, 1: Incident}
     */
    private function createIncidentWithSerial(string $serial, string $role = RolePermissionSeeder::ROLE_ADMIN, bool $paid = true): array
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        $order = Order::query()->create([
            'order_id' => 'RD-C360-SERIAL',
            'serial_number' => $serial,
            'serial_entered_at' => now(),
            'serial_entered_by_user_id' => $user->id,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Serial Customer',
            'customer_email' => 'serial@example.com',
            'customer_phone' => '9123456782',
            'cashfree_payment_id' => $paid ? 'cf_pay_c360' : null,
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Serial correction case',
            'description' => 'Needs serial correction.',
            'status' => IncidentStatus::Open,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'assigned_to_user_id' => $user->id,
        ]);

        return [$user, $incident];
    }
}
