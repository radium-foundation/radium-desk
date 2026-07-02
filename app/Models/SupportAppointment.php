<?php

namespace App\Models;

use App\Enums\SupportAppointmentTimeSlot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportAppointment extends Model
{
    protected $fillable = [
        'incident_id',
        'preferred_date',
        'preferred_time_slot',
        'phone_number',
        'additional_notes',
    ];

    protected function casts(): array
    {
        return [
            'preferred_date' => 'date',
            'preferred_time_slot' => SupportAppointmentTimeSlot::class,
        ];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }
}
