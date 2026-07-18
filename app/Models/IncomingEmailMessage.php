<?php

namespace App\Models;

use App\Enums\IncomingEmailMessageStatus;
use App\Enums\IntakeChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class IncomingEmailMessage extends Model
{
    protected $fillable = [
        'intake_channel',
        'mailbox',
        'channel',
        'provider',
        'provider_message_id',
        'rfc_message_id',
        'thread_id',
        'from_email',
        'from_name',
        'to_emails',
        'subject',
        'preview',
        'received_at',
        'attachment_count',
        'headers',
        'labels',
        'raw_payload',
        'status',
        'ignore_reason',
        'incident_id',
        'processed_at',
        'processing_error',
    ];

    protected function casts(): array
    {
        return [
            'intake_channel' => IntakeChannel::class,
            'status' => IncomingEmailMessageStatus::class,
            'to_emails' => 'array',
            'headers' => 'array',
            'labels' => 'array',
            'raw_payload' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
            'attachment_count' => 'integer',
        ];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function incidentLink(): HasOne
    {
        return $this->hasOne(IncidentIncomingEmailLink::class);
    }
}
