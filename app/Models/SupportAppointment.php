<?php

namespace App\Models;

use App\Enums\SupportAppointmentStatus;
use App\Enums\SupportAppointmentTimeSlot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportAppointment extends Model
{
    protected $fillable = [
        'incident_id',
        'preferred_date',
        'preferred_time_slot',
        'phone_number',
        'normalized_phone',
        'additional_notes',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'preferred_date' => 'date',
            'preferred_time_slot' => SupportAppointmentTimeSlot::class,
            'status' => SupportAppointmentStatus::class,
        ];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    /**
     * @param  Builder<SupportAppointment>  $query
     * @return Builder<SupportAppointment>
     */
    public function scopeScheduled(Builder $query): Builder
    {
        return $query->where('status', SupportAppointmentStatus::Scheduled);
    }

    public function isScheduled(): bool
    {
        return $this->status === SupportAppointmentStatus::Scheduled;
    }
}
