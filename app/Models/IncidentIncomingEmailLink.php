<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentIncomingEmailLink extends Model
{
    protected $fillable = [
        'incident_id',
        'incoming_email_message_id',
        'linked_at',
    ];

    protected function casts(): array
    {
        return [
            'linked_at' => 'datetime',
        ];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function incomingEmailMessage(): BelongsTo
    {
        return $this->belongsTo(IncomingEmailMessage::class);
    }
}
