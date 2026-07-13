<?php

namespace App\Models;

use App\Services\DeviceModelAliasNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceModelAlias extends Model
{
    protected $fillable = [
        'device_model_id',
        'alias',
        'normalized_alias',
    ];

    protected static function booted(): void
    {
        static::saving(function (DeviceModelAlias $alias): void {
            $alias->normalized_alias = app(DeviceModelAliasNormalizer::class)
                ->normalize($alias->alias);
        });
    }

    public function deviceModel(): BelongsTo
    {
        return $this->belongsTo(DeviceModel::class);
    }
}
