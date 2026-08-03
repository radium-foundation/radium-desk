<?php

namespace App\Models;

use App\Enums\CommunicationTemplates\CommunicationTemplateCategory;
use App\Enums\CommunicationTemplates\CommunicationTemplateStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommunicationTemplate extends Model
{
    protected $fillable = [
        'key',
        'name',
        'category',
        'channels',
        'status',
        'current_version',
        'approved_version',
        'usage_count',
        'fallback_count',
        'last_used_at',
        'last_fallback_at',
        'last_send_at',
        'blade_view',
        'notification_type',
        'is_reply_playbook',
        'playbook_scope',
        'owner_user_id',
        'runtime_source',
        'last_runtime_source',
        'last_error',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'category' => CommunicationTemplateCategory::class,
            'status' => CommunicationTemplateStatus::class,
            'channels' => 'array',
            'current_version' => 'integer',
            'approved_version' => 'integer',
            'usage_count' => 'integer',
            'fallback_count' => 'integer',
            'is_reply_playbook' => 'boolean',
            'last_used_at' => 'datetime',
            'last_fallback_at' => 'datetime',
            'last_send_at' => 'datetime',
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(CommunicationTemplateVersion::class)->orderByDesc('version');
    }

    public function usages(): HasMany
    {
        return $this->hasMany(CommunicationTemplateUsage::class)->orderByDesc('used_at');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function currentVersionRecord(): ?CommunicationTemplateVersion
    {
        if ($this->current_version <= 0) {
            return null;
        }

        return $this->versions()->where('version', $this->current_version)->first();
    }

    public function approvedVersionRecord(): ?CommunicationTemplateVersion
    {
        $version = (int) ($this->approved_version ?? 0);
        if ($version <= 0) {
            return null;
        }

        return $this->versions()->where('version', $version)->first();
    }

    public function channelLabels(): string
    {
        $channels = is_array($this->channels) ? $this->channels : [];

        return collect($channels)
            ->map(fn (string $channel): string => ucfirst(str_replace('_', ' ', $channel)))
            ->implode(', ');
    }

    public function runtimeHealth(): string
    {
        if ($this->last_error) {
            return 'error';
        }

        if ((int) $this->fallback_count > 0 && $this->last_runtime_source === 'blade') {
            return 'fallback';
        }

        return 'ok';
    }

    public function runtimeLabel(): string
    {
        return match ($this->last_runtime_source ?: $this->runtime_source) {
            'store' => 'Template Store',
            default => 'Blade',
        };
    }
}
