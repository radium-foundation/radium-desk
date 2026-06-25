<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SettingProduct extends Model
{
    protected $fillable = [
        'name',
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
