<?php

namespace App\Models;

use App\Enums\FinanceAccountType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinanceAccount extends Model
{
    protected $fillable = [
        'code',
        'name',
        'type',
        'is_system',
        'is_active',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'type' => FinanceAccountType::class,
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('code');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function journalLines(): HasMany
    {
        return $this->hasMany(FinanceJournalLine::class, 'account_id');
    }
}
