<?php

namespace Tests\Feature\CashBook;

use App\Enums\CashBookEntryType;
use App\Enums\CashBookExpenseCategory;
use App\Enums\CashBookIncomeSource;
use App\Enums\FinanceJournalSourceType;
use App\Models\CashBookEntry;
use App\Models\FinanceJournal;
use App\Models\User;
use App\Services\CashBook\CashBookSummaryService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\DisablesRequestForgeryProtection;
use Tests\TestCase;

class CashBookPhase1Test extends TestCase
{
    use DisablesRequestForgeryProtection;
    use RefreshDatabase;

    private User $admin;

    private User $agent;

    private User $employee;

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
    }

    public function test_agent_can_create_income_entry_and_journal_posts(): void
    {
        $response = $this->actingAs($this->agent)
            ->post(route('cash-book.store'), [
                'type' => CashBookEntryType::Income->value,
                'amount' => '500.00',
                'category' => CashBookIncomeSource::CashSale->value,
                'remark' => 'Walk-in customer',
                'entry_date' => now()->toDateString(),
                'confirmed' => '1',
            ]);

        $entry = CashBookEntry::query()->firstOrFail();

        $response->assertRedirect(route('cash-book.index'));
        $this->assertSame(CashBookEntryType::Income, $entry->type);
        $this->assertSame('500.00', $entry->amount);
        $this->assertSame($this->agent->id, $entry->created_by);
        $this->assertNotNull($entry->journal_id);

        $journal = FinanceJournal::query()->findOrFail($entry->journal_id);
        $this->assertSame(FinanceJournalSourceType::CashBook, $journal->source_type);
        $this->assertSame($journal->totalDebits(), $journal->totalCredits());
        $this->assertSame('500.00', $journal->totalDebits());
    }

    public function test_employee_can_create_expense_entry_and_journal_posts(): void
    {
        $this->actingAs($this->employee)
            ->post(route('cash-book.store'), [
                'type' => CashBookEntryType::Expense->value,
                'amount' => '120.50',
                'category' => CashBookExpenseCategory::Courier->value,
                'remark' => 'Blue Dart',
                'entry_date' => now()->toDateString(),
                'confirmed' => '1',
            ])
            ->assertRedirect(route('cash-book.index'));

        $entry = CashBookEntry::query()->firstOrFail();
        $this->assertSame(CashBookEntryType::Expense, $entry->type);
        $this->assertNotNull($entry->journal_id);

        $journal = $entry->journal()->with('lines.account')->firstOrFail();
        $this->assertSame(FinanceJournalSourceType::CashBook, $journal->source_type);
        $this->assertSame('120.50', $journal->totalDebits());
        $this->assertSame($journal->totalDebits(), $journal->totalCredits());
    }

    public function test_cash_in_hand_calculation(): void
    {
        $this->actingAs($this->agent)->post(route('cash-book.store'), [
            'type' => CashBookEntryType::Income->value,
            'amount' => '1000',
            'category' => CashBookIncomeSource::Accessories->value,
            'remark' => 'Accessories sale',
            'entry_date' => now()->toDateString(),
            'confirmed' => '1',
        ]);

        $this->actingAs($this->agent)->post(route('cash-book.store'), [
            'type' => CashBookEntryType::Expense->value,
            'amount' => '250',
            'category' => CashBookExpenseCategory::Tea->value,
            'remark' => 'Tea',
            'entry_date' => now()->toDateString(),
            'confirmed' => '1',
        ]);

        $summary = app(CashBookSummaryService::class)->dashboard();

        $this->assertSame(1000.0, $summary['todays_income']);
        $this->assertSame(250.0, $summary['todays_expense']);
        $this->assertSame(1000.0, $summary['all_income']);
        $this->assertSame(250.0, $summary['all_expense']);
        $this->assertSame(0.0, $summary['cash_handed_over']);
        $this->assertSame(0.0, $summary['cash_received_back']);
        $this->assertSame(750.0, $summary['available_cash']);
    }

    public function test_permission_matrix_view_create_manage(): void
    {
        $this->actingAs($this->agent)->get(route('cash-book.index'))->assertOk();
        $this->actingAs($this->employee)->get(route('cash-book.index'))->assertOk();
        $this->actingAs($this->admin)->get(route('cash-book.index'))->assertOk();

        $this->actingAs($this->agent)->get(route('cash-book.create'))->assertOk();
        $this->actingAs($this->employee)->get(route('cash-book.create'))->assertOk();

        $entry = $this->createIncomeAs($this->agent);

        $this->actingAs($this->agent)
            ->get(route('cash-book.edit', $entry))
            ->assertForbidden();

        $this->actingAs($this->agent)
            ->put(route('cash-book.update', $entry), $this->incomePayload())
            ->assertForbidden();

        $this->actingAs($this->agent)
            ->delete(route('cash-book.destroy', $entry))
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->get(route('cash-book.edit', $entry))
            ->assertRedirect(route('cash-book.edit-warning', $entry));

        $this->actingAs($this->admin)
            ->post(route('cash-book.edit-acknowledge', $entry))
            ->assertRedirect(route('cash-book.edit', $entry));

        $this->actingAs($this->admin)
            ->get(route('cash-book.edit', $entry))
            ->assertOk();

        $this->actingAs($this->admin)
            ->put(route('cash-book.update', $entry), array_merge($this->incomePayload(), [
                'amount' => '600.00',
                'remark' => 'Corrected amount',
            ]))
            ->assertRedirect(route('cash-book.index'));

        $entry->refresh();
        $this->assertSame('600.00', $entry->amount);
        $this->assertNotNull($entry->journal_id);

        $this->actingAs($this->admin)
            ->delete(route('cash-book.destroy', $entry), ['confirmed' => '1'])
            ->assertRedirect(route('cash-book.index'));

        $this->assertSoftDeleted($entry);
    }

    public function test_validation_rejects_invalid_payload(): void
    {
        $this->actingAs($this->agent)
            ->from(route('cash-book.create'))
            ->post(route('cash-book.store'), [
                'type' => CashBookEntryType::Income->value,
                'amount' => '0',
                'category' => 'not-a-source',
                'remark' => '',
                'entry_date' => '',
            ])
            ->assertRedirect(route('cash-book.create'))
            ->assertSessionHasErrors(['amount', 'category', 'remark', 'entry_date']);
    }

    public function test_admin_edit_reverses_and_reposts_journal(): void
    {
        $entry = $this->createIncomeAs($this->agent);
        $originalJournalId = $entry->journal_id;

        $this->actingAs($this->admin)
            ->post(route('cash-book.edit-acknowledge', $entry))
            ->assertRedirect(route('cash-book.edit', $entry));

        $this->actingAs($this->admin)
            ->put(route('cash-book.update', $entry), array_merge($this->incomePayload(), [
                'amount' => '800.00',
            ]))
            ->assertRedirect(route('cash-book.index'));

        $entry->refresh();
        $this->assertNotSame($originalJournalId, $entry->journal_id);
        $this->assertSame('800.00', $entry->amount);

        $reversals = FinanceJournal::query()
            ->where('source_type', FinanceJournalSourceType::CashBook)
            ->where('idempotency_key', 'like', 'cashbook:reverse:'.$entry->id.':%')
            ->count();

        $this->assertSame(1, $reversals);

        $posted = FinanceJournal::query()->findOrFail($entry->journal_id);
        $this->assertSame('800.00', $posted->totalDebits());
    }

    public function test_cash_book_appears_in_sidebar_for_employee(): void
    {
        $html = $this->actingAs($this->employee)
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(route('cash-book.index'), $html);
        $this->assertStringContainsString('Cash Book', $html);
    }

    /**
     * @return array<string, string>
     */
    private function incomePayload(): array
    {
        return [
            'type' => CashBookEntryType::Income->value,
            'amount' => '500.00',
            'category' => CashBookIncomeSource::CashSale->value,
            'remark' => 'Walk-in customer',
            'entry_date' => now()->toDateString(),
            'confirmed' => '1',
        ];
    }

    private function createIncomeAs(User $user): CashBookEntry
    {
        $this->actingAs($user)
            ->post(route('cash-book.store'), $this->incomePayload())
            ->assertRedirect(route('cash-book.index'));

        return CashBookEntry::query()->latest('id')->firstOrFail();
    }
}
