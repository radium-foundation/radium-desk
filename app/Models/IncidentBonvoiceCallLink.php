<?php

namespace App\Models;

use App\Enums\BonvoiceCallLinkType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentBonvoiceCallLink extends Model
{
    protected $fillable = [
        'incident_id',
        'bonvoice_call_event_id',
        'call_id',
        'link_type',
        'linked_at',
    ];

    protected function casts(): array
    {
        return [
            'link_type' => BonvoiceCallLinkType::class,
            'linked_at' => 'datetime',
        ];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function bonvoiceCallEvent(): BelongsTo
    {
        return $this->belongsTo(BonvoiceCallEvent::class);
    }
}
