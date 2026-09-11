<?php

namespace Tests\Feature\Pos;

use App\Models\InventoryBranch;
use App\Models\InventoryCustomer;
use App\Models\InventoryUserBranch;
use App\Models\User;
use App\Services\Pos\AdminPosCustomerImporter;
use App\Services\Pos\PosCustomerLookupService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPosCustomerImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_unique_phone_customers_once_and_rerun_creates_no_duplicate(): void
    {
        $source = [
            $this->row(101, 'Anita Rao', '9000000101', null, null, 1),
            $this->row(102, 'B2C Walk-in', '9000000102', 'b2c@example.test', null, 0),
        ];

        $first = app(AdminPosCustomerImporter::class)->execute($source);
        $this->assertSame(2, $first['counts']['created']);
        $this->assertSame(1, $first['counts']['A']);
        $this->assertSame(1, $first['counts']['B']);
        $this->assertSame(2, InventoryCustomer::query()->count());

        $second = app(AdminPosCustomerImporter::class)->execute($source);
        $this->assertSame(0, $second['counts']['created']);
        $this->assertSame(2, $second['counts']['matched']);
        $this->assertSame(2, InventoryCustomer::query()->count());
        $this->assertSame(1, InventoryCustomer::query()->where('phone', '9000000101')->count());
    }

    public function test_duplicate_phone_and_missing_phone_are_not_imported(): void
    {
        $source = [
            $this->row(201, 'Dup A', '9000000201', null, null, 1),
            $this->row(202, 'Dup B', '9000000201', null, null, 1),
            $this->row(203, 'No Phone', '', null, null, 1),
        ];

        $result = app(AdminPosCustomerImporter::class)->execute($source);

        $this->assertSame(0, $result['counts']['created']);
        $this->assertSame(2, $result['counts']['C']);
        $this->assertSame(1, $result['counts']['D']);
        $this->assertSame(0, InventoryCustomer::query()->count());
    }

    public function test_invalid_gstin_length_is_reviewed_and_blank_gstin_imports_as_b2c(): void
    {
        $source = [
            $this->row(301, 'Bad GSTIN', '9000000301', null, '27ABC', 1),
            $this->row(302, 'B2C Blank', '9000000302', null, null, 1),
            $this->row(303, 'Valid GSTIN', '9000000303', null, '27AAICP1128M1Z7', 1),
            $this->row(304, 'Dup GSTIN 1', '9000000304', null, '07AAAAA0000A1Z5', 1),
            $this->row(305, 'Dup GSTIN 2', '9000000305', null, '07AAAAA0000A1Z5', 1),
        ];

        $result = app(AdminPosCustomerImporter::class)->execute($source);

        $this->assertSame(2, $result['counts']['created']);
        $this->assertSame(3, $result['counts']['review']);
        $this->assertSame(2, InventoryCustomer::query()->count());
        $this->assertNull(InventoryCustomer::query()->where('phone', '9000000302')->value('gstin'));
        $this->assertSame('27AAICP1128M1Z7', InventoryCustomer::query()->where('phone', '9000000303')->value('gstin'));
        $this->assertNull(InventoryCustomer::query()->where('phone', '9000000301')->first());
    }

    public function test_existing_desk_customer_is_matched_and_not_updated(): void
    {
        $existing = InventoryCustomer::query()->create([
            'name' => 'Desk Original',
            'phone' => '9000000401',
            'email' => 'desk@example.test',
            'gstin' => null,
        ]);

        $result = app(AdminPosCustomerImporter::class)->execute([
            $this->row(401, 'Admin Name Change', '9000000401', 'admin@example.test', '27AAICP1128M1Z7', 1),
        ]);

        $this->assertSame(0, $result['counts']['created']);
        $this->assertSame(1, $result['counts']['matched']);
        $fresh = $existing->fresh();
        $this->assertSame('Desk Original', $fresh->name);
        $this->assertSame('desk@example.test', $fresh->email);
        $this->assertNull($fresh->gstin);
    }

    public function test_imported_customer_is_searchable_and_selection_uses_master_identity(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $seller = User::factory()->create(['is_active' => true]);
        $seller->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);
        $branch = InventoryBranch::query()->create([
            'code' => 'DELHI-RETAIL',
            'name' => 'Delhi Retail',
            'is_active' => true,
        ]);
        InventoryUserBranch::query()->create([
            'user_id' => $seller->id,
            'branch_id' => $branch->id,
        ]);

        app(AdminPosCustomerImporter::class)->execute([
            $this->row(501, 'Imported Buyer', '9822000501', 'imp@example.test', '27AAICP1128M1Z7', 2),
        ]);
        $customer = InventoryCustomer::query()->where('phone', '9822000501')->firstOrFail();

        $this->actingAs($seller)
            ->getJson(route('pos.customers.search', ['q' => 'Imported']))
            ->assertOk()
            ->assertJsonPath('customers.0.phone', '9822000501');

        $this->actingAs($seller)
            ->getJson(route('pos.customers.search', ['q' => '9822']))
            ->assertOk()
            ->assertJsonPath('customers.0.name', 'Imported Buyer');

        $this->actingAs($seller)
            ->getJson(route('pos.customers.show', $customer))
            ->assertOk()
            ->assertJsonPath('name', 'Imported Buyer')
            ->assertJsonPath('gstin', '27AAICP1128M1Z7')
            ->assertJsonPath('billing_address', null)
            ->assertJsonPath('billing_source', PosCustomerLookupService::BILLING_SOURCE_NONE);

        $this->assertSame(0, preg_match('/radiumbox_prod/', (string) file_get_contents(app_path('Services/Pos/PosCustomerLookupService.php'))));
    }

    public function test_command_dry_run_does_not_write(): void
    {
        $path = sys_get_temp_dir().'/admin-pos-import-'.uniqid().'.json';
        file_put_contents($path, json_encode([
            $this->row(601, 'Command User', '9000000601', null, null, 1),
        ]));

        $this->artisan('desk:import-admin-pos-customers', [
            'source' => $path,
            '--dry-run' => true,
        ])->assertOk()
            ->expectsOutputToContain('created=1');

        $this->assertSame(0, InventoryCustomer::query()->count());
        unlink($path);
    }

    /**
     * @return array{legacy_user_id: int, name: string, phone: string, email: ?string, gstin: ?string, address_count: int}
     */
    private function row(int $id, string $name, string $phone, ?string $email, ?string $gstin, int $addressCount): array
    {
        return [
            'legacy_user_id' => $id,
            'name' => $name,
            'phone' => $phone,
            'email' => $email,
            'gstin' => $gstin,
            'address_count' => $addressCount,
        ];
    }
}
