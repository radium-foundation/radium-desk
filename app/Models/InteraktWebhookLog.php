<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InteraktWebhookLog extends Model
{
    public const STATUS_RECEIVED = 'received';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'event_type',
        'payload',
        'raw_body',
        'request_headers',
        'processing_status',
        'processing_error',
        'processed_at',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'request_headers' => 'array',
            'processed_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }
}
