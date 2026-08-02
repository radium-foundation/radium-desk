<?php

namespace Tests\Feature\Finance;

use App\Enums\FinanceAccountType;
use App\Enums\FinanceJournalSourceType;
use App\Models\FinanceAccount;
use App\Models\FinanceJournal;
use App\Models\User;
use App\Services\Finance\Data\JournalLineDraft;
use App\Services\Finance\JournalPostingService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class JournalPostingServiceTest extends TestCase
{
    use RefreshDatabase;

    private JournalPostingService $service;

    private FinanceAccount $cash;

    private FinanceAccount $equity;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);

        $this->service = app(JournalPostingService::class);
        $this->cash = FinanceAccount::query()->where('code', '1000')->firstOrFail();
        $this->equity = FinanceAccount::query()->where('code', '3000')->firstOrFail();
        $this->actor = User::factory()->create(['is_active' => true]);
    }

    public function test_balanced_journal_posts_successfully(): void
    {
        $journal = $this->service->post(
            sourceType: FinanceJournalSourceType::OpeningBalance,
            sourceId: 1,
            idempotencyKey: 'test:balanced',
            memo: 'Opening',
            entryDate: now()->toDateString(),
            lines: [
                JournalLineDraft::debit($this->cash->id, 100.00, 'Cash'),
                JournalLineDraft::credit($this->equity->id, 100.00, 'Equity'),
            ],
            actor: $this->actor,
        );

        $this->assertSame('100.00', $journal->totalDebits());
        $this->assertSame('100.00', $journal->totalCredits());
        $this->assertCount(2, $journal->lines);
        $this->assertDatabaseCount('finance_journals', 1);
    }

    public function test_unbalanced_journal_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->post(
            sourceType: FinanceJournalSourceType::ManualAdjustment,
            sourceId: null,
            idempotencyKey: 'test:unbalanced',
            memo: 'Bad',
            entryDate: now()->toDateString(),
            lines: [
                JournalLineDraft::debit($this->cash->id, 100.00),
                JournalLineDraft::credit($this->equity->id, 50.00),
            ],
            actor: $this->actor,
        );
    }

    public function test_idempotency_returns_existing_journal(): void
    {
        $first = $this->service->post(
            sourceType: FinanceJournalSourceType::OpeningBalance,
            sourceId: 9,
            idempotencyKey: 'test:idempotent',
            memo: 'First',
            entryDate: now()->toDateString(),
            lines: [
                JournalLineDraft::debit($this->cash->id, 25.00),
                JournalLineDraft::credit($this->equity->id, 25.00),
            ],
            actor: $this->actor,
        );

        $second = $this->service->post(
            sourceType: FinanceJournalSourceType::OpeningBalance,
            sourceId: 9,
            idempotencyKey: 'test:idempotent',
            memo: 'Second attempt',
            entryDate: now()->toDateString(),
            lines: [
                JournalLineDraft::debit($this->cash->id, 25.00),
                JournalLineDraft::credit($this->equity->id, 25.00),
            ],
            actor: $this->actor,
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, FinanceJournal::query()->count());
    }

    public function test_line_must_be_debit_xor_credit(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->post(
            sourceType: FinanceJournalSourceType::ManualAdjustment,
            sourceId: null,
            idempotencyKey: 'test:both',
            memo: 'Invalid line',
            entryDate: now()->toDateString(),
            lines: [
                new JournalLineDraft($this->cash->id, 10.0, 10.0),
                JournalLineDraft::credit($this->equity->id, 10.0),
            ],
            actor: $this->actor,
        );
    }

    public function test_seeded_accounts_cover_core_types(): void
    {
        $types = FinanceAccount::query()->pluck('type')->map(fn ($t) => $t instanceof FinanceAccountType ? $t->value : $t)->unique()->sort()->values()->all();

        $this->assertContains(FinanceAccountType::Asset->value, $types);
        $this->assertContains(FinanceAccountType::Income->value, $types);
        $this->assertContains(FinanceAccountType::Expense->value, $types);
        $this->assertContains(FinanceAccountType::Equity->value, $types);
    }
}
