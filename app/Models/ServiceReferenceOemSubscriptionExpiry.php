<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceReferenceOemSubscriptionExpiry extends Model
{
    protected $fillable = [
        'service_reference',
        'oem',
        'subscription_expiry_date',
        'source_oem_handoff_session_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'subscription_expiry_date' => 'date',
        ];
    }

    public function sourceHandoffSession(): BelongsTo
    {
        return $this->belongsTo(DashboardOemHandoffSession::class, 'source_oem_handoff_session_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
