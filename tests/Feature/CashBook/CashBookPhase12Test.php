<?php

namespace Tests\Feature\CashBook;

use App\Enums\CashBookEntryType;
use App\Enums\CashBookExpenseCategory;
use App\Enums\CashBookIncomeSource;
use App\Models\AuditLog;
use App\Models\CashBookEntry;
use App\Models\User;
use App\Services\CashBook\CashBookSummaryService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\DisablesRequestForgeryProtection;
use Tests\TestCase;

class CashBookPhase12Test extends TestCase
{
    use DisablesRequestForgeryProtection;
    use RefreshDatabase;

    private User $admin;

    private User $agent;

    private User $employee;

    private User $opsAdmin;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disableRequestForgeryProtection();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->agent = User::factory()->create(['is_active' => true]);
        $this->agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->employee = User::factory()->create(['is_active' => true]);
        $this->employee->assignRole(RolePermissionSeeder::ROLE_EMPLOYEE);

        $this->opsAdmin = User::factory()->create(['is_active' => true]);
        $this->opsAdmin->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $this->superAdmin = User::factory()->create(['is_active' => true]);
        $this->superAdmin->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);
    }

    public function test_unconfirmed_store_shows_review_and_does_not_create(): void
    {
        $this->actingAs($this->agent)
            ->post(route('cash-book.store'), $this->todayIncomePayload(confirmed: false))
            ->assertOk()
            ->assertSee('Review Entry')
            ->assertSee('Confirm Entry')
            ->assertSee('Cancel');

        $this->assertSame(0, CashBookEntry::query()->count());
    }

    public function test_confirmed_store_locks_entry(): void
    {
        $this->actingAs($this->agent)
            ->post(route('cash-book.store'), $this->todayIncomePayload())
            ->assertRedirect(route('cash-book.index'));

        $entry = CashBookEntry::query()->firstOrFail();
        $this->assertTrue($entry->isLocked());
        $this->assertFalse($entry->isHistorical());
        $this->assertNotNull($entry->journal_id);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'cashbook.created',
            'auditable_id' => $entry->id,
            'user_id' => $this->agent->id,
        ]);
    }

    public function test_locked_entries_cannot_be_edited_or_deleted_by_normal_users(): void
    {
        $entry = $this->createConfirmedIncome($this->agent);

        foreach ([$this->agent, $this->employee, $this->opsAdmin] as $user) {
            $this->actingAs($user)
                ->get(route('cash-book.edit-warning', $entry))
                ->assertForbidden();

            $this->actingAs($user)
                ->delete(route('cash-book.destroy', $entry), ['confirmed' => '1'])
                ->assertForbidden();
        }
    }

    public function test_admin_edit_requires_warning_ack_and_audits_unlock(): void
    {
        $entry = $this->createConfirmedIncome($this->agent);

        $this->actingAs($this->admin)
            ->get(route('cash-book.edit', $entry))
            ->assertRedirect(route('cash-book.edit-warning', $entry));

        $this->actingAs($this->admin)
            ->get(route('cash-book.edit-warning', $entry))
            ->assertOk()
            ->assertSee('This entry has already been posted to the ledger');

        $this->actingAs($this->admin)
            ->post(route('cash-book.edit-acknowledge', $entry))
            ->assertRedirect(route('cash-book.edit', $entry));

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'cashbook.unlocked',
            'auditable_id' => $entry->id,
            'user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->put(route('cash-book.update', $entry), [
                'type' => CashBookEntryType::Income->value,
                'amount' => '510.00',
                'category' => CashBookIncomeSource::CashSale->value,
                'remark' => 'Updated after unlock',
                'entry_date' => now()->toDateString(),
            ])
            ->assertRedirect(route('cash-book.index'));

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'cashbook.edited',
            'auditable_id' => $entry->id,
            'user_id' => $this->admin->id,
        ]);
    }

    public function test_admin_delete_requires_confirmed_warning(): void
    {
        $entry = $this->createConfirmedIncome($this->agent);

        $this->actingAs($this->admin)
            ->get(route('cash-book.delete-warning', $entry))
            ->assertOk()
            ->assertSee('This action will reverse the accounting journal');

        $this->actingAs($this->admin)
            ->delete(route('cash-book.destroy', $entry))
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->delete(route('cash-book.destroy', $entry), ['confirmed' => '1'])
            ->assertRedirect(route('cash-book.index'));

        $this->assertSoftDeleted($entry);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'cashbook.deleted',
            'auditable_id' => $entry->id,
            'user_id' => $this->admin->id,
        ]);
    }

    public function test_non_super_admin_cannot_backdate(): void
    {
        $yesterday = now()->subDay()->toDateString();

        foreach ([$this->employee, $this->agent, $this->opsAdmin, $this->admin] as $user) {
            $this->actingAs($user)
                ->from(route('cash-book.create'))
                ->post(route('cash-book.store'), [
                    'type' => CashBookEntryType::Income->value,
                    'amount' => '100',
                    'category' => CashBookIncomeSource::CashSale->value,
                    'remark' => 'Backdate attempt',
                    'entry_date' => $yesterday,
                    'confirmed' => '1',
                ])
                ->assertRedirect(route('cash-book.create'))
                ->assertSessionHasErrors('entry_date');
        }

        $this->assertSame(0, CashBookEntry::query()->count());
    }

    public function test_super_admin_can_backdate_with_mandatory_reason(): void
    {
        $yesterday = now()->subDay()->toDateString();

        $this->actingAs($this->superAdmin)
            ->from(route('cash-book.create'))
            ->post(route('cash-book.store'), [
                'type' => CashBookEntryType::Income->value,
                'amount' => '100',
                'category' => CashBookIncomeSource::CashSale->value,
                'remark' => 'Late entry',
                'entry_date' => $yesterday,
                'confirmed' => '1',
            ])
            ->assertRedirect(route('cash-book.create'))
            ->assertSessionHasErrors('backdate_reason');

        $this->actingAs($this->superAdmin)
            ->post(route('cash-book.store'), [
                'type' => CashBookEntryType::Income->value,
                'amount' => '100',
                'category' => CashBookIncomeSource::CashSale->value,
                'remark' => 'Late entry',
                'entry_date' => $yesterday,
                'backdate_reason' => 'Forgot yesterday',
                'confirmed' => '1',
            ])
            ->assertRedirect(route('cash-book.index'));

        $entry = CashBookEntry::query()->firstOrFail();
        $this->assertSame($yesterday, $entry->entry_date->toDateString());
        $this->assertSame('Forgot yesterday', $entry->backdate_reason);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'cashbook.backdated',
            'auditable_id' => $entry->id,
            'user_id' => $this->superAdmin->id,
        ]);
    }

    public function test_historical_import_super_admin_only_and_dashboard_rules(): void
    {
        $this->actingAs($this->admin)
            ->get(route('cash-book.historical.create'))
            ->assertForbidden();

        $past = now()->subDays(10)->toDateString();

        $this->actingAs($this->superAdmin)
            ->post(route('cash-book.historical.store'), [
                'type' => CashBookEntryType::Income->value,
                'amount' => '5000',
                'category' => CashBookIncomeSource::Other->value,
                'person' => 'Opening cash',
                'remark' => 'April ledger',
                'entry_date' => $past,
                'historical_reason' => 'Historical Import — opening balance',
                'confirmed' => '1',
            ])
            ->assertRedirect(route('cash-book.index', ['period' => 'all']));

        $entry = CashBookEntry::query()->firstOrFail();
        $this->assertTrue($entry->isHistorical());
        $this->assertTrue($entry->isLocked());
        $this->assertNotNull($entry->imported_at);

        $summary = app(CashBookSummaryService::class)->dashboard();
        $this->assertSame(0.0, $summary['todays_income']);
        $this->assertSame(0.0, $summary['todays_expense']);
        $this->assertSame(5000.0, $summary['available_cash']);

        $this->actingAs($this->superAdmin)
            ->get(route('cash-book.index', ['period' => 'all']))
            ->assertOk()
            ->assertSee('Historical')
            ->assertSee('Opening cash');

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'cashbook.historical_imported',
            'auditable_id' => $entry->id,
            'user_id' => $this->superAdmin->id,
        ]);
    }

    public function test_regression_search_filters_and_today_entry_still_work(): void
    {
        $this->createConfirmedIncome($this->agent, [
            'amount' => '150',
            'remark' => 'Phase12 regression',
            'person' => 'Ravi',
        ]);

        $this->actingAs($this->agent)
            ->get(route('cash-book.index', ['period' => 'today', 'q' => 'Phase12']))
            ->assertOk()
            ->assertSee('Phase12 regression')
            ->assertSee('Locked');

        $summary = app(CashBookSummaryService::class)->dashboard();
        $this->assertSame(150.0, $summary['todays_income']);
        $this->assertSame(150.0, $summary['available_cash']);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function todayIncomePayload(bool $confirmed = true, array $overrides = []): array
    {
        return array_merge([
            'type' => CashBookEntryType::Income->value,
            'amount' => '500.00',
            'category' => CashBookIncomeSource::CashSale->value,
            'remark' => 'Walk-in customer',
            'entry_date' => now()->toDateString(),
            'confirmed' => $confirmed ? '1' : '0',
        ], $overrides);
    }

    /**
     * @param  array<string, string>  $overrides
     */
    private function createConfirmedIncome(User $user, array $overrides = []): CashBookEntry
    {
        $this->actingAs($user)
            ->post(route('cash-book.store'), $this->todayIncomePayload(overrides: $overrides))
            ->assertRedirect(route('cash-book.index'));

        return CashBookEntry::query()->latest('id')->firstOrFail();
    }
}
