<?php

namespace App\Models;

use App\Data\RemarkMetadata;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;

class Remark extends Model
{
    protected $fillable = [
        'user_id',
        'remarkable_type',
        'remarkable_id',
        'body',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function remarkable(): MorphTo
    {
        return $this->morphTo();
    }

    public function mentions(): HasMany
    {
        return $this->hasMany(RemarkMention::class);
    }

    public function metadataDto(): RemarkMetadata
    {
        return RemarkMetadata::fromArray($this->metadata);
    }

    /**
     * @return Collection<int, User>
     */
    public function mentionedUsers(): Collection
    {
        if (! $this->relationLoaded('mentions')) {
            $this->load('mentions.user');
        }

        return $this->mentions
            ->map(fn (RemarkMention $mention): ?User => $mention->user)
            ->filter()
            ->unique(fn (User $user): int => $user->id)
            ->values();
    }

    /**
     * @return list<string>
     */
    public function mentionedUserNames(): array
    {
        return $this->mentionedUsers()
            ->map(fn (User $user): string => (string) $user->name)
            ->values()
            ->all();
    }
}
