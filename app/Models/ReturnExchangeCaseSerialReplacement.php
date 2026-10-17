<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnExchangeCaseSerialReplacement extends Model
{
    protected $fillable = [
        'return_exchange_case_id',
        'return_exchange_case_receipt_id',
        'returned_inventory_serial_id',
        'replacement_inventory_serial_id',
        'inventory_sale_id',
        'hardware_fulfilment_serial_id',
        'verified_unit_value',
        'idempotency_key',
        'performed_by',
        'performed_at',
    ];

    protected function casts(): array
    {
        return [
            'performed_at' => 'datetime',
        ];
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(ReturnExchangeCase::class, 'return_exchange_case_id');
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(ReturnExchangeCaseReceipt::class, 'return_exchange_case_receipt_id');
    }

    public function returnedSerial(): BelongsTo
    {
        return $this->belongsTo(InventorySerial::class, 'returned_inventory_serial_id');
    }

    public function replacementSerial(): BelongsTo
    {
        return $this->belongsTo(InventorySerial::class, 'replacement_inventory_serial_id');
    }
}
