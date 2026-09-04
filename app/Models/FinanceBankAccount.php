<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FinanceBankAccount extends Model
{
    protected $fillable = [
        'bank_name',
        'account_name',
        'last_four',
        'gl_account_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('bank_name')->orderBy('account_name');
    }

    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'gl_account_id');
    }

    public function upiProfile(): HasOne
    {
        return $this->hasOne(FinanceBankAccountUpiProfile::class, 'finance_bank_account_id');
    }
}
