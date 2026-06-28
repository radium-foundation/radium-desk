<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashfreeWebhookLog extends Model
{
    public const STATUS_RECEIVED = 'received';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'webhook_version',
        'cf_payment_id',
        'request_headers',
        'request_payload',
        'raw_body',
        'received_at',
        'source_ip',
        'user_agent',
        'processing_status',
        'processing_error',
        'processed_at',
        'incident_id',
    ];

    protected function casts(): array
    {
        return [
            'request_headers' => 'array',
            'request_payload' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function eventType(): ?string
    {
        $payload = $this->request_payload ?? [];

        foreach (['type', 'event', 'event_type', 'payment_status'] as $key) {
            if (! isset($payload[$key]) || ! is_scalar($payload[$key])) {
                continue;
            }

            $value = trim((string) $payload[$key]);

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }
}
