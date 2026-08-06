<?php

namespace App\Services\IraMemory;

use App\Enums\IraMemoryCreatedFrom;
use App\Enums\IraMemoryDecisionKind;
use App\Enums\IraMemoryPatternKind;
use App\Enums\IraMemoryRelationType;
use App\Enums\IraMemorySource;
use App\Enums\IraMemoryStatus;
use App\Enums\IraMemoryType;
use App\Models\IncomingEmailMessage;
use App\Models\IraMemory;
use App\Models\IraMemoryRelation;
use App\Models\User;
use InvalidArgumentException;

/**
 * Canonical knowledge-layer service for ira_memories.
 */
class IraMemoryService
{
    public function __construct(
        private readonly IraMemoryMatcher $matcher,
    ) {}

    public function create(array $attributes): IraMemory
    {
        return IraMemory::query()->create($attributes);
    }

    public function update(IraMemory $memory, array $attributes): IraMemory
    {
        unset($attributes['source'], $attributes['created_from']);

        $memory->fill($attributes)->save();

        return $memory->fresh() ?? $memory;
    }

    public function upsertFromTeaching(
        IraMemoryPatternKind $patternKind,
        string $patternValue,
        IraMemoryDecisionKind $decisionKind,
        string $decisionValue,
        User $actor,
        IraMemoryCreatedFrom $createdFrom,
        int $confidence = 90,
        IraMemorySource $source = IraMemorySource::Email,
        ?IncomingEmailMessage $sourceMessage = null,
    ): IraMemory {
        $patternValue = trim($patternValue);

        if ($patternValue === '') {
            throw new InvalidArgumentException('IRA Memory pattern_value cannot be empty.');
        }

        $memory = IraMemory::query()->firstOrNew([
            'pattern_kind' => $patternKind->value,
            'pattern_value' => $patternValue,
            'decision_kind' => $decisionKind->value,
            'uniqueness_guard' => IraMemory::UNIQUENESS_GUARD_LIVE,
        ]);

        $isNew = ! $memory->exists;

        $memory->fill([
            'decision_value' => $decisionValue,
            'confidence' => max(1, min(100, $confidence)),
            'created_by_user_id' => $actor->id,
            'status' => IraMemoryStatus::Active,
            'memory_type' => IraMemoryType::fromDecisionKind($decisionKind),
            'source' => $source,
        ]);

        if ($isNew) {
            $memory->created_from = $createdFrom;

            if ($sourceMessage !== null) {
                $memory->created_from_type = $sourceMessage::class;
                $memory->created_from_id = $sourceMessage->id;
            }
        }

        $memory->save();

        return $memory->fresh() ?? $memory;
    }

    public function activate(IraMemory $memory): IraMemory
    {
        $memory->forceFill([
            'status' => IraMemoryStatus::Active,
            'uniqueness_guard' => IraMemory::UNIQUENESS_GUARD_LIVE,
            'merged_into_memory_id' => null,
        ])->save();

        return $memory->fresh() ?? $memory;
    }

    public function disable(IraMemory $memory): IraMemory
    {
        $memory->forceFill([
            'status' => IraMemoryStatus::Disabled,
            'uniqueness_guard' => IraMemory::UNIQUENESS_GUARD_LIVE,
        ])->save();

        return $memory->fresh() ?? $memory;
    }

    public function softDelete(IraMemory $memory): IraMemory
    {
        if ($memory->status === IraMemoryStatus::Merged) {
            throw new InvalidArgumentException('Merged memories cannot be deleted; merge already retired them.');
        }

        $memory->forceFill([
            'status' => IraMemoryStatus::Deleted,
            'uniqueness_guard' => 'deleted:'.$memory->id,
        ])->save();

        $memory->delete();

        return IraMemory::withTrashed()->find($memory->id) ?? $memory;
    }

    /**
     * Dry-run match for admin “Test Memory” (no usage recording, no pipeline side effects).
     *
     * @return list<IraMemoryMatch>
     */
    public function testMatch(
        ?string $fromEmail = null,
        ?string $subject = null,
        ?string $preview = null,
        ?string $mailbox = null,
        IraMemorySource $source = IraMemorySource::Email,
    ): array {
        $probe = new IncomingEmailMessage([
            'from_email' => $fromEmail,
            'subject' => $subject,
            'preview' => $preview,
            'mailbox' => $mailbox,
        ]);

        return $this->matcher->matchForEmailMessage($probe, $source);
    }

    public function merge(IraMemory $source, IraMemory $target, ?User $actor = null): IraMemory
    {
        if ($source->is($target)) {
            throw new InvalidArgumentException('Cannot merge an IRA Memory into itself.');
        }

        if ($target->status === IraMemoryStatus::Merged) {
            throw new InvalidArgumentException('Cannot merge into a memory that is already merged.');
        }

        $source->forceFill([
            'status' => IraMemoryStatus::Merged,
            'merged_into_memory_id' => $target->id,
            'uniqueness_guard' => 'merged:'.$source->id,
        ])->save();

        $lastUsedAt = collect([$target->last_used_at, $source->last_used_at])
            ->filter()
            ->sort()
            ->last();

        $target->forceFill([
            'times_used' => (int) $target->times_used + (int) $source->times_used,
            'last_used_at' => $lastUsedAt,
            'status' => IraMemoryStatus::Active,
            'uniqueness_guard' => IraMemory::UNIQUENESS_GUARD_LIVE,
        ])->save();

        IraMemoryRelation::query()->firstOrCreate(
            [
                'memory_id' => $source->id,
                'related_memory_id' => $target->id,
                'relation_type' => IraMemoryRelationType::DuplicateOf->value,
            ],
            [
                'created_by_user_id' => $actor?->id,
            ],
        );

        return $target->fresh() ?? $target;
    }

    /**
     * @return list<IraMemoryMatch>
     */
    public function match(IncomingEmailMessage $message, IraMemorySource $source = IraMemorySource::Email): array
    {
        return $this->matcher->matchForEmailMessage($message, $source);
    }

    public function recordUsage(IraMemory $memory): void
    {
        $memory->recordUsage();
    }
}
