<?php

namespace App\Models;

use App\Enums\ReturnExchangeCaseSourceKind;
use App\Enums\ReturnExchangeCaseStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReturnExchangeCase extends Model
{
    protected $fillable = [
        'case_no',
        'source_kind',
        'status',
        'branch_id',
        'inventory_sale_id',
        'order_id',
        'hardware_fulfilment_id',
        'idempotency_key',
        'reason',
        'requester_remarks',
        'requested_by',
    ];

    protected function casts(): array
    {
        return [
            'source_kind' => ReturnExchangeCaseSourceKind::class,
            'status' => ReturnExchangeCaseStatus::class,
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(InventoryBranch::class, 'branch_id');
    }

    public function inventorySale(): BelongsTo
    {
        return $this->belongsTo(InventorySale::class, 'inventory_sale_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function hardwareFulfilment(): BelongsTo
    {
        return $this->belongsTo(HardwareFulfilment::class, 'hardware_fulfilment_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ReturnExchangeCaseLine::class)->orderBy('line_no');
    }
}
