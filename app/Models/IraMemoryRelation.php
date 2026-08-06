<?php

namespace App\Models;

use App\Enums\IraMemoryRelationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IraMemoryRelation extends Model
{
    protected $table = 'ira_memory_relations';

    protected $fillable = [
        'memory_id',
        'related_memory_id',
        'relation_type',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'relation_type' => IraMemoryRelationType::class,
        ];
    }

    public function memory(): BelongsTo
    {
        return $this->belongsTo(IraMemory::class, 'memory_id');
    }

    public function relatedMemory(): BelongsTo
    {
        return $this->belongsTo(IraMemory::class, 'related_memory_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
