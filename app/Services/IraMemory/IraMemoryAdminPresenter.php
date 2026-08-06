<?php

namespace App\Services\IraMemory;

use App\Enums\IraMemoryRelationType;
use App\Enums\IraMemoryStatus;
use App\Models\IncomingEmailMessage;
use App\Models\IraMemory;
use App\Models\IraMemoryRelation;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * List / detail payloads for Administration → IRA Memory.
 */
class IraMemoryAdminPresenter
{
    public function __construct(
        private readonly IraMemoryExplainService $explainService,
    ) {}

    /**
     * @param  iterable<IraMemory>  $memories
     * @return list<array<string, mixed>>
     */
    public function listRows(iterable $memories): array
    {
        $rows = [];

        foreach ($memories as $memory) {
            $rows[] = $this->listRow($memory);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function listRow(IraMemory $memory): array
    {
        $explain = $this->explainService->forMemory($memory);
        $confidence = (int) $explain['confidence'];
        $band = (string) $explain['confidence_band'];

        return [
            'id' => (int) $memory->id,
            'uuid' => (string) $memory->uuid,
            'pattern_kind' => $memory->pattern_kind?->value,
            'pattern_kind_label' => $memory->pattern_kind?->label() ?? '—',
            'pattern_value' => (string) $memory->pattern_value,
            'memory_type' => $memory->memory_type?->value,
            'memory_type_label' => $memory->memory_type?->label() ?? '—',
            'source' => $memory->source?->value,
            'source_label' => $memory->source?->label() ?? '—',
            'status' => $memory->status?->value,
            'status_label' => $memory->status?->label() ?? '—',
            'decision_kind' => $memory->decision_kind?->value,
            'decision_kind_label' => $memory->decision_kind?->label() ?? '—',
            'decision_value' => (string) $memory->decision_value,
            'decision_label' => $explain['decision']['label'],
            'confidence' => $confidence,
            'confidence_band' => $band,
            'confidence_band_class' => 'ira-lc-conf--'.strtolower($band),
            'times_used' => (int) $memory->times_used,
            'last_used_at' => $memory->last_used_at,
            'last_used_label' => $memory->last_used_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?? '—',
            'created_from' => $memory->created_from?->value,
            'created_from_label' => $memory->created_from?->label() ?? '—',
            'created_by' => $memory->creator?->name ?: ($memory->creator?->email ?? '—'),
            'url' => route('admin.ira-memory.show', $memory),
            'can_toggle' => in_array($memory->status, [IraMemoryStatus::Active, IraMemoryStatus::Disabled], true),
            'is_active' => $memory->status === IraMemoryStatus::Active,
            'can_edit' => in_array($memory->status, [IraMemoryStatus::Active, IraMemoryStatus::Disabled], true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(IraMemory $memory): array
    {
        $row = $this->listRow($memory);
        $explain = $this->explainService->forMemory($memory);
        $band = (string) $explain['confidence_band'];

        $memory->loadMissing([
            'creator:id,name,first_name,last_name,email',
            'mergedInto:id,uuid,pattern_kind,pattern_value,status',
            'mergeSources:id,uuid,pattern_kind,pattern_value,status,times_used',
        ]);

        return array_merge($row, [
            'reason' => $memory->reason,
            'created_at' => $memory->created_at,
            'created_at_label' => $memory->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?? '—',
            'created_by_email' => $memory->creator?->email,
            'updated_at_label' => $memory->updated_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?? '—',
            'created_from_type' => $memory->created_from_type,
            'created_from_id' => $memory->created_from_id,
            'merged_into' => $memory->mergedInto ? [
                'id' => $memory->mergedInto->id,
                'label' => ($memory->mergedInto->pattern_kind?->label() ?? 'Pattern')
                    .' · '.$memory->mergedInto->pattern_value,
                'url' => route('admin.ira-memory.show', $memory->mergedInto),
            ] : null,
            'merge_sources' => $memory->mergeSources->map(fn (IraMemory $source): array => [
                'id' => $source->id,
                'label' => ($source->pattern_kind?->label() ?? 'Pattern').' · '.$source->pattern_value,
                'times_used' => (int) $source->times_used,
                'url' => route('admin.ira-memory.show', $source),
            ])->values()->all(),
            'explain' => [
                'why' => $explain['why'],
                'matched_fields' => $explain['matched_fields'],
                'confidence' => $explain['confidence'],
                'confidence_band' => $band,
                'confidence_band_class' => 'ira-lc-conf--'.strtolower($band),
                'pattern' => $explain['matched_pattern']['label'].' · '.$explain['matched_pattern']['value'],
                'rule_source' => trim($explain['created_from_label'].' · '.$explain['source_label'], ' ·'),
                'usage' => (int) $explain['previous_usage']['times_used'],
                'last_matched_label' => $memory->last_used_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?? 'Never',
                'decision_label' => $explain['decision']['label'],
            ],
            'explainability' => $explain,
            'example_emails' => $this->exampleEmails($memory),
            'linked_rules' => $this->linkedRules($memory),
            'related_memories' => $this->relatedMemories($memory),
        ]);
    }

    /**
     * @param  list<IraMemoryMatch>  $matches
     * @return list<array<string, mixed>>
     */
    public function matchPreviewRows(array $matches): array
    {
        $rows = [];

        foreach ($matches as $match) {
            if (! $match instanceof IraMemoryMatch) {
                continue;
            }

            $rows[] = array_merge($this->listRow($match->memory), [
                'matched_on' => $match->matchedOn,
                'matched_value' => $match->matchedValue,
                'explainability' => $this->explainService->forMemory($match->memory),
            ]);
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function exampleEmails(IraMemory $memory, int $limit = 10): array
    {
        /** @var Collection<int, IncomingEmailMessage> $matched */
        $matched = IncomingEmailMessage::query()
            ->where(function ($query) use ($memory): void {
                $query->where('matched_ira_memory_id', $memory->id)
                    ->orWhere('matched_learning_rule_id', $memory->id);
            })
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'from_email', 'subject', 'preview', 'received_at', 'status']);

        $byId = $matched->keyBy('id');

        if (
            $memory->created_from_type === IncomingEmailMessage::class
            && filled($memory->created_from_id)
            && ! $byId->has((int) $memory->created_from_id)
        ) {
            $origin = IncomingEmailMessage::query()
                ->whereKey((int) $memory->created_from_id)
                ->first(['id', 'from_email', 'subject', 'preview', 'received_at', 'status']);

            if ($origin !== null) {
                $matched = $matched->prepend($origin)->take($limit)->values();
            }
        }

        $originId = $memory->created_from_type === IncomingEmailMessage::class
            ? (int) $memory->created_from_id
            : null;

        return $matched->map(function (IncomingEmailMessage $message) use ($originId): array {
            return [
                'id' => (int) $message->id,
                'from_email' => (string) ($message->from_email ?: '—'),
                'subject' => (string) ($message->subject ?: '(no subject)'),
                'preview' => Str::limit((string) ($message->preview ?: ''), 140),
                'received_at' => $message->received_at,
                'received_label' => $message->received_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?? '—',
                'origin' => $originId !== null && $originId === (int) $message->id,
                'learning_center_url' => route('admin.incoming-emails.index', ['queue' => 'needs_human']),
            ];
        })->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function linkedRules(IraMemory $memory): array
    {
        return IraMemory::query()
            ->where('pattern_kind', $memory->pattern_kind?->value)
            ->where('pattern_value', $memory->pattern_value)
            ->where('id', '!=', $memory->id)
            ->where('status', '!=', IraMemoryStatus::Deleted->value)
            ->orderByDesc('confidence')
            ->limit(20)
            ->get()
            ->map(fn (IraMemory $sibling): array => [
                'id' => $sibling->id,
                'decision_label' => $this->explainService->decisionLabel($sibling),
                'status_label' => $sibling->status?->label() ?? '—',
                'confidence' => (int) $sibling->confidence,
                'url' => route('admin.ira-memory.show', $sibling),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function relatedMemories(IraMemory $memory): array
    {
        $relations = IraMemoryRelation::query()
            ->with([
                'memory:id,uuid,pattern_kind,pattern_value,status',
                'relatedMemory:id,uuid,pattern_kind,pattern_value,status',
            ])
            ->where(function ($query) use ($memory): void {
                $query->where('memory_id', $memory->id)
                    ->orWhere('related_memory_id', $memory->id);
            })
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        return $relations->map(function (IraMemoryRelation $relation) use ($memory): array {
            $other = $relation->memory_id === $memory->id
                ? $relation->relatedMemory
                : $relation->memory;

            $type = $relation->relation_type instanceof IraMemoryRelationType
                ? $relation->relation_type
                : IraMemoryRelationType::tryFrom((string) $relation->relation_type);

            return [
                'relation_type' => $type?->value ?? (string) $relation->relation_type,
                'relation_label' => $type?->label() ?? (string) $relation->relation_type,
                'id' => $other?->id,
                'label' => $other
                    ? (($other->pattern_kind?->label() ?? 'Pattern').' · '.$other->pattern_value)
                    : '—',
                'status_label' => $other?->status?->label() ?? '—',
                'url' => $other ? route('admin.ira-memory.show', $other) : null,
            ];
        })->values()->all();
    }
}
