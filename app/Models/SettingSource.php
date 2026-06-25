<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SettingSource extends Model
{
    protected $fillable = [
        'key',
        'label',
        'icon',
        'sort_order',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_enabled' => 'boolean',
        ];
    }
}
