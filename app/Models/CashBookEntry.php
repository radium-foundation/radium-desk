<?php

namespace App\Models;

use App\Enums\CashBookEntryType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CashBookEntry extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'entry_no',
        'type',
        'amount',
        'category',
        'person',
        'remark',
        'entry_date',
        'created_by',
        'updated_by',
        'journal_id',
        'deleted_by',
        'locked_at',
        'is_historical',
        'backdate_reason',
        'historical_reason',
        'imported_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => CashBookEntryType::class,
            'amount' => 'decimal:2',
            'entry_date' => 'date',
            'locked_at' => 'datetime',
            'is_historical' => 'boolean',
            'imported_at' => 'datetime',
        ];
    }

    public function isIncome(): bool
    {
        return $this->type === CashBookEntryType::Income;
    }

    public function isExpense(): bool
    {
        return $this->type === CashBookEntryType::Expense;
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    public function isHistorical(): bool
    {
        return (bool) $this->is_historical;
    }

    public function categoryLabel(): string
    {
        if ($this->isIncome()) {
            $source = \App\Enums\CashBookIncomeSource::tryFrom($this->category);

            return $source?->label() ?? $this->category;
        }

        $category = \App\Enums\CashBookExpenseCategory::tryFrom($this->category);

        return $category?->label() ?? $this->category;
    }

    public function personFieldLabel(): string
    {
        return $this->isIncome() ? 'Received From' : 'Paid To';
    }

    public function categoryFieldLabel(): string
    {
        return $this->isIncome() ? 'Income Source' : 'Expense Category';
    }

    /**
     * @return array<string, mixed>
     */
    public function auditSnapshot(): array
    {
        return [
            'entry_no' => $this->entry_no,
            'type' => $this->type?->value,
            'amount' => (string) $this->amount,
            'category' => $this->category,
            'person' => $this->person,
            'remark' => $this->remark,
            'entry_date' => $this->entry_date?->toDateString(),
            'is_historical' => $this->is_historical,
            'backdate_reason' => $this->backdate_reason,
            'historical_reason' => $this->historical_reason,
            'journal_id' => $this->journal_id,
            'locked_at' => $this->locked_at?->toIso8601String(),
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function deleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(FinanceJournal::class, 'journal_id');
    }
}
