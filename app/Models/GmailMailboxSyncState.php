<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GmailMailboxSyncState extends Model
{
    protected $fillable = [
        'mailbox',
        'history_id',
        'profile_history_id',
        'enabled_at',
        'last_synced_at',
        'last_attempted_at',
        'baselined_at',
        'last_error',
        'messages_processed_last_run',
        'messages_skipped_last_run',
        'messages_retried_last_run',
        'messages_failed_last_run',
        'history_pages_last_run',
        'cursor_advances_last_run',
        'last_sync_duration_ms',
        'last_response_latency_ms',
        'oauth_status',
        'consecutive_failures',
    ];

    protected function casts(): array
    {
        return [
            'enabled_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'last_attempted_at' => 'datetime',
            'baselined_at' => 'datetime',
            'messages_processed_last_run' => 'integer',
            'messages_skipped_last_run' => 'integer',
            'messages_retried_last_run' => 'integer',
            'messages_failed_last_run' => 'integer',
            'history_pages_last_run' => 'integer',
            'cursor_advances_last_run' => 'integer',
            'last_sync_duration_ms' => 'integer',
            'last_response_latency_ms' => 'integer',
            'consecutive_failures' => 'integer',
        ];
    }

    public function isBaselined(): bool
    {
        return filled($this->history_id) && $this->baselined_at !== null;
    }

    public function cursorLag(): ?int
    {
        if (! filled($this->history_id) || ! filled($this->profile_history_id)) {
            return null;
        }

        if (! ctype_digit((string) $this->history_id) || ! ctype_digit((string) $this->profile_history_id)) {
            return null;
        }

        return max(0, (int) $this->profile_history_id - (int) $this->history_id);
    }
}
