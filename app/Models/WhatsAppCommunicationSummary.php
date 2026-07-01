<?php

namespace App\Models;

use App\Enums\WhatsAppConversationStatus;
use Illuminate\Database\Eloquent\Model;

class WhatsAppCommunicationSummary extends Model
{
    protected $table = 'whatsapp_communication_summaries';

    protected $fillable = [
        'customer_phone',
        'conversation_id',
        'interakt_customer_id',
        'conversation_status',
        'messages_exchanged_count',
        'unread_count',
        'last_sender',
        'last_template_name',
        'last_message_id',
        'last_delivery_status',
        'last_activity_at',
        'last_communication_at',
    ];

    protected function casts(): array
    {
        return [
            'conversation_status' => WhatsAppConversationStatus::class,
            'messages_exchanged_count' => 'integer',
            'unread_count' => 'integer',
            'last_activity_at' => 'datetime',
            'last_communication_at' => 'datetime',
        ];
    }
}
