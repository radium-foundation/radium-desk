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

class CashBookPhase11Test extends TestCase
{
    use DisablesRequestForgeryProtection;
    use RefreshDatabase;

    private User $admin;

    private User $agent;

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
    }

    public function test_income_stores_optional_received_from(): void
    {
        $this->actingAs($this->agent)
            ->post(route('cash-book.store'), [
                'type' => CashBookEntryType::Income->value,
                'amount' => '350.00',
                'category' => CashBookIncomeSource::CashSale->value,
                'person' => 'Walk-in Customer',
                'remark' => 'Invoice 125',
                'entry_date' => now()->toDateString(),
                'confirmed' => '1',
            ])
            ->assertRedirect(route('cash-book.index'));

        $entry = CashBookEntry::query()->firstOrFail();
        $this->assertSame('Walk-in Customer', $entry->person);
        $this->assertNotNull($entry->journal_id);
    }

    public function test_expense_stores_optional_paid_to(): void
    {
        $this->actingAs($this->agent)
            ->post(route('cash-book.store'), [
                'type' => CashBookEntryType::Expense->value,
                'amount' => '80.00',
                'category' => CashBookExpenseCategory::Courier->value,
                'person' => 'Blue Dart',
                'remark' => 'Parcel',
                'entry_date' => now()->toDateString(),
                'confirmed' => '1',
            ])
            ->assertRedirect(route('cash-book.index'));

        $entry = CashBookEntry::query()->firstOrFail();
        $this->assertSame('Blue Dart', $entry->person);
    }

    public function test_person_is_optional(): void
    {
        $this->actingAs($this->agent)
            ->post(route('cash-book.store'), [
                'type' => CashBookEntryType::Income->value,
                'amount' => '100.00',
                'category' => CashBookIncomeSource::Other->value,
                'person' => '',
                'remark' => 'Misc cash',
                'entry_date' => now()->toDateString(),
                'confirmed' => '1',
            ])
            ->assertRedirect(route('cash-book.index'));

        $this->assertNull(CashBookEntry::query()->firstOrFail()->person);
    }

    public function test_dashboard_shows_available_cash_label_and_totals(): void
    {
        $this->actingAs($this->agent)->post(route('cash-book.store'), [
            'type' => CashBookEntryType::Income->value,
            'amount' => '1000',
            'category' => CashBookIncomeSource::Accessories->value,
            'person' => 'ABC Traders',
            'remark' => 'Accessories',
            'entry_date' => now()->toDateString(),
                'confirmed' => '1',
        ]);

        $this->actingAs($this->agent)->post(route('cash-book.store'), [
            'type' => CashBookEntryType::Expense->value,
            'amount' => '200',
            'category' => CashBookExpenseCategory::Tea->value,
            'person' => 'Tea Stall',
            'remark' => 'Tea',
            'entry_date' => now()->toDateString(),
                'confirmed' => '1',
        ]);

        $summary = app(CashBookSummaryService::class)->dashboard();
        $this->assertSame(800.0, $summary['available_cash']);
        $this->assertSame(0.0, $summary['cash_handed_over']);
        $this->assertSame(0.0, $summary['cash_received_back']);

        $this->actingAs($this->agent)
            ->get(route('cash-book.index'))
            ->assertOk()
            ->assertSee('Available Cash')
            ->assertSee('Income Source / Expense Category')
            ->assertSee('Received From / Paid To')
            ->assertSee('ABC Traders')
            ->assertSee('Tea Stall')
            ->assertDontSee('Cash In Hand', false);
    }

    public function test_live_search_matches_remark_person_category_amount_and_reference(): void
    {
        $this->actingAs($this->agent)->post(route('cash-book.store'), [
            'type' => CashBookEntryType::Income->value,
            'amount' => '777.00',
            'category' => CashBookIncomeSource::RefurbishedDeviceSale->value,
            'person' => 'Ramesh',
            'remark' => 'Sold old MFS110',
            'entry_date' => now()->toDateString(),
                'confirmed' => '1',
        ]);

        $this->actingAs($this->agent)->post(route('cash-book.store'), [
            'type' => CashBookEntryType::Expense->value,
            'amount' => '50.00',
            'category' => CashBookExpenseCategory::Porter->value,
            'person' => 'Auto Driver',
            'remark' => 'Coolie',
            'entry_date' => now()->toDateString(),
                'confirmed' => '1',
        ]);

        $entry = CashBookEntry::query()->where('person', 'Ramesh')->firstOrFail();

        $this->actingAs($this->agent)
            ->get(route('cash-book.index', ['q' => 'MFS110', 'period' => 'today']))
            ->assertOk()
            ->assertSee('Ramesh')
            ->assertDontSee('Auto Driver');

        $this->actingAs($this->agent)
            ->get(route('cash-book.index', ['q' => 'Ramesh', 'period' => 'today']))
            ->assertOk()
            ->assertSee('Sold old MFS110');

        $this->actingAs($this->agent)
            ->get(route('cash-book.index', ['q' => 'Refurbished', 'period' => 'today']))
            ->assertOk()
            ->assertSee('Ramesh');

        $this->actingAs($this->agent)
            ->get(route('cash-book.index', ['q' => '777', 'period' => 'today']))
            ->assertOk()
            ->assertSee('Ramesh');

        $this->actingAs($this->agent)
            ->get(route('cash-book.index', ['q' => $entry->entry_no, 'period' => 'today']))
            ->assertOk()
            ->assertSee('Ramesh');
    }

    public function test_period_and_type_filters(): void
    {
        $this->actingAs($this->agent)->post(route('cash-book.store'), [
            'type' => CashBookEntryType::Income->value,
            'amount' => '100',
            'category' => CashBookIncomeSource::CashSale->value,
            'remark' => 'Today income',
            'entry_date' => now()->toDateString(),
                'confirmed' => '1',
        ]);

        $this->actingAs($this->agent)->post(route('cash-book.store'), [
            'type' => CashBookEntryType::Expense->value,
            'amount' => '40',
            'category' => CashBookExpenseCategory::Stationery->value,
            'remark' => 'Today expense',
            'entry_date' => now()->toDateString(),
                'confirmed' => '1',
        ]);

        CashBookEntry::query()->create([
            'entry_no' => 'CB-'.now()->format('Y').'-009999',
            'type' => CashBookEntryType::Income,
            'amount' => 55,
            'category' => CashBookIncomeSource::Other->value,
            'remark' => 'Yesterday income',
            'entry_date' => now()->subDay()->toDateString(),
            'created_by' => $this->agent->id,
        ]);

        $this->actingAs($this->agent)
            ->get(route('cash-book.index', ['period' => 'today', 'type' => 'income']))
            ->assertOk()
            ->assertSee('Today income')
            ->assertDontSee('Today expense')
            ->assertDontSee('Yesterday income');

        $this->actingAs($this->agent)
            ->get(route('cash-book.index', ['period' => 'yesterday']))
            ->assertOk()
            ->assertSee('Yesterday income')
            ->assertDontSee('Today income');

        $this->actingAs($this->agent)
            ->get(route('cash-book.index', [
                'period' => 'custom',
                'date_from' => now()->subDay()->toDateString(),
                'date_to' => now()->subDay()->toDateString(),
            ]))
            ->assertOk()
            ->assertSee('Yesterday income')
            ->assertDontSee('Today expense');
    }

    public function test_permissions_and_journal_regression(): void
    {
        $this->actingAs($this->agent)
            ->post(route('cash-book.store'), [
                'type' => CashBookEntryType::Income->value,
                'amount' => '200.00',
                'category' => CashBookIncomeSource::CashSale->value,
                'person' => 'Customer Name',
                'remark' => 'Regression income',
                'entry_date' => now()->toDateString(),
                'confirmed' => '1',
            ])
            ->assertRedirect(route('cash-book.index'));

        $entry = CashBookEntry::query()->firstOrFail();
        $this->assertNotNull($entry->journal_id);

        $journal = FinanceJournal::query()->findOrFail($entry->journal_id);
        $this->assertSame(FinanceJournalSourceType::CashBook, $journal->source_type);
        $this->assertSame($journal->totalDebits(), $journal->totalCredits());

        $this->actingAs($this->agent)
            ->get(route('cash-book.edit', $entry))
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->post(route('cash-book.edit-acknowledge', $entry))
            ->assertRedirect(route('cash-book.edit', $entry));

        $this->actingAs($this->admin)
            ->put(route('cash-book.update', $entry), [
                'type' => CashBookEntryType::Income->value,
                'amount' => '220.00',
                'category' => CashBookIncomeSource::CashSale->value,
                'person' => 'Customer Name',
                'remark' => 'Regression income updated',
                'entry_date' => now()->toDateString(),
            ])
            ->assertRedirect(route('cash-book.index'));

        $entry->refresh();
        $this->assertSame('220.00', $entry->amount);
        $this->assertSame('Customer Name', $entry->person);
        $this->assertNotNull($entry->journal_id);
    }

    public function test_create_form_uses_phase_11_labels(): void
    {
        $this->actingAs($this->agent)
            ->get(route('cash-book.create'))
            ->assertOk()
            ->assertSee('Income Source')
            ->assertSee('Expense Category')
            ->assertSee('Received From')
            ->assertSee('Paid To')
            ->assertDontSee('>Source<', false);
    }
}
