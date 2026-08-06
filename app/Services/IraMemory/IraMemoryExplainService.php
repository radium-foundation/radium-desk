<?php

namespace App\Services\IraMemory;

use App\Enums\IncomingEmailIgnoreLearningAction;
use App\Enums\IncomingEmailImportance;
use App\Enums\IncomingEmailOperatorClassification;
use App\Enums\IraMemoryDecisionKind;
use App\Models\IraMemory;
use App\Models\User;

/**
 * Deterministic explainability DTOs for IRA Memory (no AI).
 */
class IraMemoryExplainService
{
    /**
     * @return array{
     *     why: string,
     *     matched_pattern: array{kind: ?string, value: string, label: string},
     *     matched_fields: string,
     *     confidence: int,
     *     confidence_band: string,
     *     decision: array{memory_type: ?string, kind: ?string, value: string, label: string},
     *     previous_usage: array{times_used: int, last_used_at: ?string},
     *     source: ?string,
     *     source_label: string,
     *     created_from: ?string,
     *     created_from_label: string,
     *     memory_id: int,
     *     examples: list<string>
     * }
     */
    public function forMemory(IraMemory $memory): array
    {
        $confidence = max(0, min(100, (int) $memory->confidence));
        $patternKind = $memory->pattern_kind;
        $patternValue = (string) $memory->pattern_value;
        $patternLabel = $patternKind?->label() ?? 'Pattern';
        $decisionLabel = $this->decisionLabel($memory);

        $why = filled($memory->reason)
            ? (string) $memory->reason
            : sprintf(
                'Matched an operator-confirmed learning rule on %s → %s.',
                $patternLabel,
                $decisionLabel,
            );

        return [
            'why' => $why,
            'matched_pattern' => [
                'kind' => $patternKind?->value,
                'value' => $patternValue,
                'label' => $patternLabel,
            ],
            'matched_fields' => $patternLabel.': '.$patternValue,
            'confidence' => $confidence,
            'confidence_band' => $this->confidenceBand($confidence),
            'decision' => [
                'memory_type' => $memory->memory_type?->value,
                'kind' => $memory->decision_kind?->value,
                'value' => (string) $memory->decision_value,
                'label' => $decisionLabel,
            ],
            'previous_usage' => [
                'times_used' => (int) $memory->times_used,
                'last_used_at' => $memory->last_used_at?->toIso8601String(),
            ],
            'source' => $memory->source?->value,
            'source_label' => $memory->source?->label() ?? '—',
            'created_from' => $memory->created_from?->value,
            'created_from_label' => $memory->created_from?->label() ?? '—',
            'memory_id' => (int) $memory->id,
            'examples' => [
                'Pattern: '.$patternLabel.' = '.$patternValue,
                'Decision: '.$decisionLabel,
            ],
        ];
    }

    public function confidenceBand(int $confidence): string
    {
        if ($confidence >= 75) {
            return 'High';
        }

        if ($confidence >= 45) {
            return 'Medium';
        }

        return 'Low';
    }

    public function decisionLabel(IraMemory $memory): string
    {
        $kind = $memory->decision_kind;
        $value = (string) $memory->decision_value;

        return match ($kind) {
            IraMemoryDecisionKind::Assign => $this->assignLabel($value),
            IraMemoryDecisionKind::Classification => IncomingEmailOperatorClassification::tryFrom($value)?->label()
                ?? 'Classification',
            IraMemoryDecisionKind::Importance => IncomingEmailImportance::tryFrom($value)?->label()
                ?? 'Importance',
            IraMemoryDecisionKind::Ignore => IncomingEmailIgnoreLearningAction::tryFrom($value)?->label()
                ?? 'Ignore',
            IraMemoryDecisionKind::Disposition => $kind->label().': '.$value,
            default => $kind?->label() ?? (filled($value) ? $value : 'Decision'),
        };
    }

    private function assignLabel(string $userId): string
    {
        $id = (int) $userId;

        if ($id <= 0) {
            return 'Assign to teammate';
        }

        $user = User::query()->find($id, ['id', 'name', 'first_name', 'last_name', 'email']);

        if ($user === null) {
            return 'Assign to user #'.$id;
        }

        $name = trim((string) ($user->name ?: trim(($user->first_name ?? '').' '.($user->last_name ?? ''))));

        return 'Assign to '.($name !== '' ? $name : $user->email);
    }
}
