<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnExchangeCaseReceipt extends Model
{
    protected $fillable = [
        'return_exchange_case_id',
        'return_exchange_case_line_id',
        'idempotency_key',
        'inventory_serial_id',
        'qty_received',
        'serial_number_scanned',
        'received_at',
        'received_by',
        'product_match_passed',
        'device_operates_ok',
        'condition_code',
        'accessories_verified',
        'repair_hold',
        'inspected_at',
        'inspected_by',
        'serial_status_after_inspection',
        'internal_notes',
    ];

    protected function casts(): array
    {
        return [
            'qty_received' => 'integer',
            'received_at' => 'datetime',
            'inspected_at' => 'datetime',
            'product_match_passed' => 'boolean',
            'device_operates_ok' => 'boolean',
            'accessories_verified' => 'boolean',
            'repair_hold' => 'boolean',
        ];
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(ReturnExchangeCase::class, 'return_exchange_case_id');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(ReturnExchangeCaseLine::class, 'return_exchange_case_line_id');
    }

    public function serial(): BelongsTo
    {
        return $this->belongsTo(InventorySerial::class, 'inventory_serial_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }

    public function serialReplacement(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(ReturnExchangeCaseSerialReplacement::class, 'return_exchange_case_receipt_id');
    }
}
