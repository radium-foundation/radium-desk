<?php

namespace App\Models;

use App\Enums\FinanceExpenseStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class FinanceExpense extends Model
{
    public const RECEIPT_DISK = 'local';

    public const LEGACY_RECEIPT_DISK = 'public';

    protected $fillable = [
        'expense_no',
        'expense_date',
        'expense_category_id',
        'amount',
        'payment_method_id',
        'cash_account_id',
        'bank_account_id',
        'description',
        'receipt_path',
        'status',
        'posted_at',
        'posted_by',
        'created_by',
        'journal_id',
    ];

    protected function casts(): array
    {
        return [
            'expense_date' => 'date',
            'amount' => 'decimal:2',
            'status' => FinanceExpenseStatus::class,
            'posted_at' => 'datetime',
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === FinanceExpenseStatus::Draft;
    }

    public function isPosted(): bool
    {
        return $this->status === FinanceExpenseStatus::Posted;
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceExpenseCategory::class, 'expense_category_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(FinancePaymentMethod::class, 'payment_method_id');
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(FinanceCashAccount::class, 'cash_account_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(FinanceBankAccount::class, 'bank_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(FinanceJournal::class, 'journal_id');
    }

    public function hasReceipt(): bool
    {
        return $this->receiptDisk() !== null;
    }

    public function receiptDisk(): ?string
    {
        if (! filled($this->receipt_path)) {
            return null;
        }

        if (Storage::disk(self::RECEIPT_DISK)->exists($this->receipt_path)) {
            return self::RECEIPT_DISK;
        }

        if (Storage::disk(self::LEGACY_RECEIPT_DISK)->exists($this->receipt_path)) {
            return self::LEGACY_RECEIPT_DISK;
        }

        return null;
    }
}
