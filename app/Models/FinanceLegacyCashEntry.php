<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceLegacyCashEntry extends Model
{
    public const TYPE_CREDIT = 'credit';

    public const TYPE_DEBIT = 'debit';

    public const IMPORT_IMPORTED = 'imported';

    public const IMPORT_CONFLICT = 'conflict';

    public const REVIEW_OK = 'ok';

    public const REVIEW_NEEDS_REVIEW = 'needs_review';

    protected $fillable = [
        'legacy_source',
        'legacy_database',
        'legacy_table',
        'legacy_transaction_id',
        'idempotency_key',
        'original_created_at',
        'original_updated_at',
        'original_amount_raw',
        'amount',
        'entry_type',
        'amount_type',
        'description',
        'legacy_created_by',
        'legacy_admin_name',
        'desk_user_id',
        'import_status',
        'review_status',
        'review_reason',
        'imported_at',
    ];

    protected function casts(): array
    {
        return [
            'legacy_transaction_id' => 'integer',
            'original_created_at' => 'datetime',
            'original_updated_at' => 'datetime',
            'amount' => 'decimal:2',
            'imported_at' => 'datetime',
        ];
    }

    public function deskUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'desk_user_id');
    }

    public function isCredit(): bool
    {
        return $this->entry_type === self::TYPE_CREDIT;
    }

    public function isDebit(): bool
    {
        return $this->entry_type === self::TYPE_DEBIT;
    }

    public function needsReview(): bool
    {
        return $this->review_status === self::REVIEW_NEEDS_REVIEW;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $inner) use ($term): void {
            $inner->where('legacy_transaction_id', $term)
                ->orWhere('description', 'like', '%'.$term.'%')
                ->orWhere('legacy_admin_name', 'like', '%'.$term.'%')
                ->orWhere('legacy_created_by', 'like', '%'.$term.'%')
                ->orWhere('amount_type', 'like', '%'.$term.'%')
                ->orWhere('idempotency_key', 'like', '%'.$term.'%');
        });
    }
}
