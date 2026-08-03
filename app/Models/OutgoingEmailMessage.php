<?php

namespace App\Models;

use App\Enums\OutgoingEmailMessageStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutgoingEmailMessage extends Model
{
    protected $fillable = [
        'in_reply_to_incoming_email_message_id',
        'incident_id',
        'order_id',
        'mailbox',
        'to_email',
        'subject',
        'body_html',
        'body_text',
        'preview',
        'thread_id',
        'rfc_message_id',
        'provider',
        'provider_message_id',
        'template_key',
        'sent_by_user_id',
        'sent_at',
        'status',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'status' => OutgoingEmailMessageStatus::class,
            'sent_at' => 'datetime',
        ];
    }

    public function inReplyTo(): BelongsTo
    {
        return $this->belongsTo(IncomingEmailMessage::class, 'in_reply_to_incoming_email_message_id');
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }

    public function displayPreview(): ?string
    {
        if (filled($this->preview)) {
            return (string) $this->preview;
        }

        if (filled($this->body_text)) {
            return mb_substr(trim((string) $this->body_text), 0, 280);
        }

        if (filled($this->body_html)) {
            return mb_substr(trim(strip_tags((string) $this->body_html)), 0, 280);
        }

        return null;
    }
}
