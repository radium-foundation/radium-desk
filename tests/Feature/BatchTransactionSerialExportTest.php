<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\WorkspaceContext;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

class BatchTransactionSerialExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function createAdmin(): User
    {
        $admin = User::factory()->create(['name' => 'Serial Export Admin']);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $admin;
    }

    /**
     * @return array{order: Order, incident: Incident}
     */
    private function createCase(User $creator, string $suffix, ?string $serial = null): array
    {
        $order = Order::query()->create([
            'order_id' => "RD-EXPORT-{$suffix}",
            'serial_number' => $serial,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'cashfree_payment_id' => "cf_export_{$suffix}",
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => "SC-EXPORT-{$suffix}",
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => "Export {$suffix}",
            'description' => "Export {$suffix}.",
            'status' => IncidentStatus::Open->value,
            'created_by' => $creator->id,
        ]);

        return compact('order', 'incident');
    }

    public function test_admin_can_download_excel_for_selected_incidents_only(): void
    {
        $admin = $this->createAdmin();
        $includedA = $this->createCase($admin, 'A', 'SN-INCLUDE-A');
        $includedB = $this->createCase($admin, 'B', 'SN-INCLUDE-B');
        $excluded = $this->createCase($admin, 'C', 'SN-EXCLUDED');

        $response = $this->actingAs($admin)
            ->get(route('dashboard.components.batch-transaction.serial-export', [
                'incident_ids' => [$includedA['incident']->id, $includedB['incident']->id],
                'context' => WorkspaceContext::Dashboard->value,
            ]));

        $response->assertOk();
        $response->assertDownload();

        $path = $response->getFile()->getPathname();

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertIsString($sheet);
        $this->assertStringContainsString('SN-INCLUDE-A', $sheet);
        $this->assertStringContainsString('SN-INCLUDE-B', $sheet);
        $this->assertStringNotContainsString('SN-EXCLUDED', $sheet);
        $zip->close();
    }

    public function test_export_returns_422_when_no_serial_numbers_are_available(): void
    {
        $admin = $this->createAdmin();
        $case = $this->createCase($admin, 'EMPTY', null);

        $this->actingAs($admin)
            ->getJson(route('dashboard.components.batch-transaction.serial-export', [
                'incident_ids' => [$case['incident']->id],
                'context' => WorkspaceContext::Dashboard->value,
            ]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'No serial numbers are available for the selected orders.');
    }

    public function test_export_is_forbidden_for_non_admin_users(): void
    {
        $viewer = User::factory()->create();
        $admin = $this->createAdmin();
        $case = $this->createCase($admin, 'AUTH', 'SN-AUTH');

        $this->actingAs($viewer)
            ->get(route('dashboard.components.batch-transaction.serial-export', [
                'incident_ids' => [$case['incident']->id],
            ]))
            ->assertForbidden();
    }

    public function test_export_does_not_mutate_orders_or_assignments(): void
    {
        $admin = $this->createAdmin();
        $case = $this->createCase($admin, 'READ', 'SN-READ-ONLY');
        $orderBefore = $case['order']->fresh();

        $this->actingAs($admin)
            ->get(route('dashboard.components.batch-transaction.serial-export', [
                'incident_ids' => [$case['incident']->id],
            ]))
            ->assertOk();

        $orderAfter = $case['order']->fresh();
        $this->assertSame($orderBefore->transaction_id, $orderAfter->transaction_id);
        $this->assertSame($orderBefore->serial_number, $orderAfter->serial_number);
        $this->assertSame($orderBefore->updated_at?->toJSON(), $orderAfter->updated_at?->toJSON());
    }

    public function test_batch_transaction_modal_includes_excel_export_control(): void
    {
        $admin = $this->createAdmin();
        $case = $this->createCase($admin, 'UI', 'SN-UI');

        $this->actingAs($admin)
            ->get(route('dashboard.components.batch-transaction', [
                'incident_ids' => [$case['incident']->id],
                'context' => WorkspaceContext::Dashboard->value,
            ]))
            ->assertOk()
            ->assertSee('data-copy-all-serials', false)
            ->assertSee('data-export-serials-excel', false)
            ->assertSee('bi-file-earmark-excel', false);
    }
}
