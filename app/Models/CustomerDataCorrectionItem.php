<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerDataCorrectionItem extends Model
{
    protected $fillable = [
        'customer_data_correction_id',
        'field_name',
        'old_value',
        'new_value',
    ];

    public function correction(): BelongsTo
    {
        return $this->belongsTo(CustomerDataCorrection::class, 'customer_data_correction_id');
    }
}
