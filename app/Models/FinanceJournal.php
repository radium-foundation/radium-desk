<?php

namespace App\Models;

use App\Enums\FinanceJournalSourceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinanceJournal extends Model
{
    protected $fillable = [
        'journal_no',
        'entry_date',
        'memo',
        'source_type',
        'source_id',
        'idempotency_key',
        'posted_by',
        'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'source_type' => FinanceJournalSourceType::class,
            'posted_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(FinanceJournalLine::class, 'journal_id')->orderBy('line_no');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function totalDebits(): string
    {
        return number_format((float) $this->lines->sum(fn (FinanceJournalLine $line) => (float) $line->debit), 2, '.', '');
    }

    public function totalCredits(): string
    {
        return number_format((float) $this->lines->sum(fn (FinanceJournalLine $line) => (float) $line->credit), 2, '.', '');
    }
}
