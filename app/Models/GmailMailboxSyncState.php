<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GmailMailboxSyncState extends Model
{
    protected $fillable = [
        'mailbox',
        'history_id',
        'enabled_at',
        'last_synced_at',
        'baselined_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'enabled_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'baselined_at' => 'datetime',
        ];
    }

    public function isBaselined(): bool
    {
        return filled($this->history_id) && $this->baselined_at !== null;
    }
}
