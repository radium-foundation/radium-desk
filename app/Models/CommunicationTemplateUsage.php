<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunicationTemplateUsage extends Model
{
    protected $fillable = [
        'communication_template_id',
        'communication_template_version_id',
        'used_by',
        'channel',
        'communication_type',
        'edit_percent',
        'send_duration_ms',
        'runtime_source',
        'used_fallback',
        'used_at',
    ];

    protected function casts(): array
    {
        return [
            'used_at' => 'datetime',
            'edit_percent' => 'integer',
            'send_duration_ms' => 'integer',
            'used_fallback' => 'boolean',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(CommunicationTemplate::class, 'communication_template_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(CommunicationTemplateVersion::class, 'communication_template_version_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'used_by');
    }
}
