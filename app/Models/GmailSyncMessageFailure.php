<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GmailSyncMessageFailure extends Model
{
    protected $fillable = [
        'mailbox',
        'message_id',
        'endpoint',
        'http_status',
        'error_payload',
        'history_id',
        'attempt_count',
        'elapsed_ms',
        'request_id',
    ];

    protected function casts(): array
    {
        return [
            'error_payload' => 'array',
            'http_status' => 'integer',
            'attempt_count' => 'integer',
            'elapsed_ms' => 'integer',
        ];
    }
}
