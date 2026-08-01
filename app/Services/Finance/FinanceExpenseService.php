<?php

namespace App\Services\Finance;

use App\Enums\FinanceExpenseStatus;
use App\Models\FinanceExpense;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class FinanceExpenseService
{
    public function __construct(
        private readonly FinanceExpenseReferenceService $referenceService,
    ) {}

    /**
     * @param  array{
     *     expense_date: string,
     *     expense_category_id: int,
     *     amount: numeric,
     *     payment_method_id: int,
     *     cash_account_id?: int|null,
     *     bank_account_id?: int|null,
     *     description: string,
     *     receipt?: UploadedFile|null
     * }  $data
     */
    public function create(User $actor, array $data): FinanceExpense
    {
        return DB::transaction(function () use ($actor, $data): FinanceExpense {
            $expense = FinanceExpense::query()->create([
                'expense_no' => $this->referenceService->generate(),
                'expense_date' => $data['expense_date'],
                'expense_category_id' => $data['expense_category_id'],
                'amount' => round((float) $data['amount'], 2),
                'payment_method_id' => $data['payment_method_id'],
                'cash_account_id' => $data['cash_account_id'] ?? null,
                'bank_account_id' => $data['bank_account_id'] ?? null,
                'description' => $data['description'],
                'status' => FinanceExpenseStatus::Draft,
                'created_by' => $actor->id,
            ]);

            if (($data['receipt'] ?? null) instanceof UploadedFile) {
                $expense->update([
                    'receipt_path' => $this->storeReceipt($expense, $data['receipt']),
                ]);
            }

            return $expense->fresh([
                'category',
                'paymentMethod',
                'cashAccount',
                'bankAccount',
                'creator',
            ]);
        });
    }

    /**
     * @param  array{
     *     expense_date: string,
     *     expense_category_id: int,
     *     amount: numeric,
     *     payment_method_id: int,
     *     cash_account_id?: int|null,
     *     bank_account_id?: int|null,
     *     description: string,
     *     receipt?: UploadedFile|null
     * }  $data
     */
    public function updateDraft(FinanceExpense $expense, array $data): FinanceExpense
    {
        return DB::transaction(function () use ($expense, $data): FinanceExpense {
            $locked = FinanceExpense::query()
                ->whereKey($expense->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureDraft($locked);

            $locked->update([
                'expense_date' => $data['expense_date'],
                'expense_category_id' => $data['expense_category_id'],
                'amount' => round((float) $data['amount'], 2),
                'payment_method_id' => $data['payment_method_id'],
                'cash_account_id' => $data['cash_account_id'] ?? null,
                'bank_account_id' => $data['bank_account_id'] ?? null,
                'description' => $data['description'],
            ]);

            if (($data['receipt'] ?? null) instanceof UploadedFile) {
                $this->replaceReceipt($locked, $data['receipt']);
            }

            return $locked->fresh([
                'category',
                'paymentMethod',
                'cashAccount',
                'bankAccount',
                'creator',
            ]);
        });
    }

    public function post(FinanceExpense $expense, User $actor): FinanceExpense
    {
        return DB::transaction(function () use ($expense, $actor): FinanceExpense {
            $locked = FinanceExpense::query()
                ->whereKey($expense->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureDraft($locked);

            $locked->update([
                'status' => FinanceExpenseStatus::Posted,
                'posted_at' => now(),
                'posted_by' => $actor->id,
            ]);

            return $locked->fresh([
                'category',
                'paymentMethod',
                'cashAccount',
                'bankAccount',
                'creator',
                'poster',
            ]);
        });
    }

    private function ensureDraft(FinanceExpense $expense): void
    {
        if (! $expense->isDraft()) {
            throw ValidationException::withMessages([
                'status' => 'Only draft expenses can be changed. Posted expenses are immutable.',
            ]);
        }
    }

    private function storeReceipt(FinanceExpense $expense, UploadedFile $file): string
    {
        return $file->store("finance/expenses/{$expense->id}", 'public');
    }

    private function replaceReceipt(FinanceExpense $expense, UploadedFile $file): void
    {
        if (filled($expense->receipt_path)) {
            Storage::disk('public')->delete($expense->receipt_path);
        }

        $expense->update([
            'receipt_path' => $this->storeReceipt($expense, $file),
        ]);
    }
}
