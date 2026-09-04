<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryBranch;
use App\Models\InventoryOpeningImportBatch;
use App\Models\User;
use App\Services\Inventory\Opening\OpeningInventoryWorkbookWriter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class OpeningInventoryImportAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_hardware_and_agent_cannot_preview_or_apply_opening_import(): void
    {
        InventoryBranch::query()->create([
            'code' => 'DELHI-WH',
            'name' => 'Delhi Warehouse',
            'is_active' => true,
        ]);

        $hardware = User::factory()->create(['is_active' => true]);
        $hardware->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($hardware)->get(route('inventory.opening-import.create'))->assertForbidden();
        $this->actingAs($agent)->get(route('inventory.opening-import.create'))->assertForbidden();
        $this->actingAs($hardware)->post(route('inventory.opening-import.preview'), [
            'workbook' => $this->uploadedWorkbook(),
        ])->assertForbidden();
    }

    public function test_admin_preview_does_not_apply_stock(): void
    {
        InventoryBranch::query()->create([
            'code' => 'DELHI-WH',
            'name' => 'Delhi Warehouse',
            'is_active' => true,
        ]);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $response = $this->actingAs($admin)->post(route('inventory.opening-import.preview'), [
            'workbook' => $this->uploadedWorkbook(),
        ]);

        $response->assertOk();
        $this->assertDatabaseCount('inventory_products', 0);
        $this->assertDatabaseCount('inventory_serials', 0);
    }

    public function test_apply_without_confirmation_is_rejected(): void
    {
        InventoryBranch::query()->create([
            'code' => 'DELHI-WH',
            'name' => 'Delhi Warehouse',
            'is_active' => true,
        ]);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $preview = $this->actingAs($admin)->post(route('inventory.opening-import.preview'), [
            'workbook' => $this->uploadedWorkbook(),
        ]);
        $preview->assertOk();

        $batchId = InventoryOpeningImportBatch::query()->value('id');
        $this->actingAs($admin)
            ->from(route('inventory.opening-import.create'))
            ->post(route('inventory.opening-import.apply', $batchId))
            ->assertRedirect();

        $this->assertDatabaseCount('inventory_serials', 0);
    }

    public function test_artisan_preview_rejects_hardware_actor(): void
    {
        InventoryBranch::query()->create([
            'code' => 'DELHI-WH',
            'name' => 'Delhi Warehouse',
            'is_active' => true,
        ]);

        $hardware = User::factory()->create(['is_active' => true]);
        $hardware->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);
        $path = $this->workbookPath();

        $this->artisan('inventory:opening-import', [
            'path' => $path,
            '--actor' => $hardware->email,
        ])->assertFailed();

        $this->assertDatabaseCount('inventory_serials', 0);
        $this->assertDatabaseCount('inventory_opening_import_batches', 0);
    }

    public function test_artisan_preview_rejects_inactive_admin(): void
    {
        InventoryBranch::query()->create([
            'code' => 'DELHI-WH',
            'name' => 'Delhi Warehouse',
            'is_active' => true,
        ]);

        $admin = User::factory()->create(['is_active' => false]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->artisan('inventory:opening-import', [
            'path' => $this->workbookPath(),
            '--actor' => $admin->email,
        ])->assertFailed();

        $this->assertDatabaseCount('inventory_opening_import_batches', 0);
    }

    public function test_artisan_preview_allows_admin_without_applying_stock(): void
    {
        InventoryBranch::query()->create([
            'code' => 'DELHI-WH',
            'name' => 'Delhi Warehouse',
            'is_active' => true,
        ]);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->artisan('inventory:opening-import', [
            'path' => $this->workbookPath(),
            '--actor' => $admin->email,
        ])->assertSuccessful();

        $this->assertDatabaseCount('inventory_serials', 0);
        $this->assertDatabaseCount('inventory_opening_import_batches', 1);
    }

    private function workbookPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'opening').'.xlsx';
        app(OpeningInventoryWorkbookWriter::class)->write(
            $path,
            [[
                '2026-09-04', 'DELHI-WH', 'Warehouse', 'PMTMFS110Z', '', 'Mantra MFS 110', 'Y',
                'New', 'Available', 'SN-AUTH-1', 1, '1800.00', '', '18', '84716050', 'QA', '', '',
            ]],
            [[
                'PMTMFS110Z', 'Mantra MFS 110', '', 'Y', '84716050', '18', '2117.80', '1800.00', 'Y', '',
            ]],
            [['DELHI-WH', 'Delhi Warehouse', 'Warehouse', '', 'Delhi', 'New Delhi', '', 'Y', '']],
        );

        return $path;
    }

    private function uploadedWorkbook(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'opening').'.xlsx';
        app(OpeningInventoryWorkbookWriter::class)->write(
            $path,
            [[
                '2026-09-04', 'DELHI-WH', 'Warehouse', 'PMTMFS110Z', '', 'Mantra MFS 110', 'Y',
                'New', 'Available', 'SN-AUTH-1', 1, '1800.00', '', '18', '84716050', 'QA', '', '',
            ]],
            [[
                'PMTMFS110Z', 'Mantra MFS 110', '', 'Y', '84716050', '18', '2117.80', '1800.00', 'Y', '',
            ]],
            [['DELHI-WH', 'Delhi Warehouse', 'Warehouse', '', 'Delhi', 'New Delhi', '', 'Y', '']],
        );

        return new UploadedFile($path, 'opening.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
