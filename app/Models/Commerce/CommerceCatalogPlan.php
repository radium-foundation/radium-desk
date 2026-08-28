<?php

namespace App\Models\Commerce;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommerceCatalogPlan extends Model
{
    public const TYPE_RD = 'rd';

    public const TYPE_AMC = 'amc';

    protected $fillable = [
        'model_id',
        'plan_type',
        'display_name',
        'short_name',
        'selling_price',
        'publish_price',
        'regular_price',
        'hsn_code',
        'sort_order',
        'is_enabled',
        'legacy_reference',
    ];

    protected function casts(): array
    {
        return [
            'selling_price' => 'decimal:2',
            'publish_price' => 'decimal:2',
            'regular_price' => 'decimal:2',
            'sort_order' => 'integer',
            'is_enabled' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<CommerceCatalogModel, $this>
     */
    public function model(): BelongsTo
    {
        return $this->belongsTo(CommerceCatalogModel::class, 'model_id');
    }

    public function isRdPlan(): bool
    {
        return $this->plan_type === self::TYPE_RD;
    }

    public function isAmcPlan(): bool
    {
        return $this->plan_type === self::TYPE_AMC;
    }
}
