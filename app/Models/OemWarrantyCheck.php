<?php

namespace App\Models;

use App\Enums\OemWarrantyCaptureMethod;
use App\Enums\OemWarrantyLookupOutcome;
use App\Enums\OemWarrantyProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OemWarrantyCheck extends Model
{
    protected $fillable = [
        'order_id',
        'oem_warranty_lookup_session_id',
        'provider',
        'serial_number',
        'outcome',
        'capture_method',
        'verified_by_user_id',
        'checked_at',
        'warranty_expiry_date',
        'rd_expiry_date',
        'mantratec_model',
        'morpho_order_number',
        'morpho_whitelisting_date',
        'morpho_expiry_date',
        'morpho_validity_years',
        'provider_payload',
        'is_current',
    ];

    protected function casts(): array
    {
        return [
            'provider' => OemWarrantyProvider::class,
            'outcome' => OemWarrantyLookupOutcome::class,
            'capture_method' => OemWarrantyCaptureMethod::class,
            'checked_at' => 'datetime',
            'warranty_expiry_date' => 'date',
            'rd_expiry_date' => 'date',
            'morpho_whitelisting_date' => 'date',
            'morpho_expiry_date' => 'date',
            'provider_payload' => 'array',
            'is_current' => 'boolean',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(OemWarrantyLookupSession::class, 'oem_warranty_lookup_session_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }
}
