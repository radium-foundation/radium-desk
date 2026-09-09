<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use App\Services\Finance\LegacyCashImportService;
use App\Services\Finance\LegacyCashSourceReader;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\LegacyCashApprovedHistory;
use Tests\TestCase;

class LegacyCashUiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);

        User::factory()->create([
            'name' => 'Gunjan Kumar',
            'is_active' => false,
        ]);
        User::factory()->create([
            'name' => 'Rafaquat',
            'is_active' => true,
        ]);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $rows = app(LegacyCashSourceReader::class)->hydrate(LegacyCashApprovedHistory::mappingSample());
        app(LegacyCashImportService::class)->import($rows, dryRun: false);
    }

    public function test_admin_can_view_read_only_legacy_cash(): void
    {
        $this->actingAs($this->admin)
            ->get(route('finance.legacy-cash.index'))
            ->assertOk()
            ->assertSee('Legacy Cash — RadiumBox Admin')
            ->assertSee('Read-only')
            ->assertSee('Gunjan Kumar')
            ->assertSee('Rafaquat')
            ->assertSee('Avinash')
            ->assertSee('Unmapped')
            ->assertSee('Needs review')
            ->assertSee('legacy:radiumbox_prod:expenses:157')
            ->assertSee('Separated from operational Finance')
            ->assertDontSee('LEGACY_RADIUMBOX_DB_PASSWORD')
            ->assertDontSee('New Expense')
            ->assertDontSee('Add Entry');

        $this->actingAs($this->admin)
            ->from(route('finance.legacy-cash.index'))
            ->post(route('finance.legacy-cash.index'))
            ->assertMethodNotAllowed();
    }

    public function test_legacy_cash_filters_by_type_and_review(): void
    {
        $this->actingAs($this->admin)
            ->get(route('finance.legacy-cash.index', ['review_status' => 'needs_review']))
            ->assertOk()
            ->assertSee('26.40 L Given to sir')
            ->assertDontSee('Office tea');

        $this->actingAs($this->admin)
            ->get(route('finance.legacy-cash.index', ['type' => 'credit']))
            ->assertOk()
            ->assertSee('Old Admin Balance Added')
            ->assertDontSee('Office tea');
    }

    public function test_agent_cannot_view_legacy_cash(): void
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->get(route('finance.legacy-cash.index'))
            ->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('finance.legacy-cash.index'))
            ->assertRedirect();
    }
}
