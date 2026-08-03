<?php

namespace App\Models;

use App\Enums\CommunicationTemplates\CommunicationTemplateGreetingStyle;
use App\Enums\CommunicationTemplates\CommunicationTemplateSignatureMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommunicationTemplateVersion extends Model
{
    protected $fillable = [
        'communication_template_id',
        'version',
        'subject',
        'greeting_style',
        'body_html',
        'signature_mode',
        'channels',
        'variables',
        'change_reason',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'greeting_style' => CommunicationTemplateGreetingStyle::class,
            'signature_mode' => CommunicationTemplateSignatureMode::class,
            'channels' => 'array',
            'variables' => 'array',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(CommunicationTemplate::class, 'communication_template_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function usages(): HasMany
    {
        return $this->hasMany(CommunicationTemplateUsage::class);
    }
}
