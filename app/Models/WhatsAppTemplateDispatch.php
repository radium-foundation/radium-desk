<?php

namespace App\Models;

use App\Enums\WhatsAppTemplateDispatchStatus;
use App\Enums\WhatsAppTemplateTriggerSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppTemplateDispatch extends Model
{
    protected $table = 'whatsapp_template_dispatches';

    protected $fillable = [
        'incident_id',
        'order_id',
        'triggered_by_user_id',
        'template_key',
        'template_name',
        'template_display_name',
        'template_purpose',
        'trigger_source',
        'status',
        'customer_phone',
        'interakt_message_id',
        'error_message',
        'context',
        'dispatched_at',
    ];

    protected function casts(): array
    {
        return [
            'trigger_source' => WhatsAppTemplateTriggerSource::class,
            'status' => WhatsAppTemplateDispatchStatus::class,
            'context' => 'array',
            'dispatched_at' => 'datetime',
        ];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }
}
