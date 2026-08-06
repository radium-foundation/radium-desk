<?php

namespace App\Services\IncomingEmail;

use App\Data\IncomingEmail\IncomingEmailLearningApplicationResult;
use App\Data\IncomingEmail\IncomingEmailLearningRuleMatch;
use App\Enums\IncomingEmailIgnoreLearningAction;
use App\Enums\IncomingEmailImportance;
use App\Enums\IncomingEmailLearningDecisionType;
use App\Enums\IncomingEmailLearningRuleType;
use App\Enums\IncomingEmailLearningScope;
use App\Enums\IncomingEmailMessageStatus;
use App\Enums\IncomingEmailOperatorClassification;
use App\Models\IncomingEmailIgnoreStat;
use App\Models\IncomingEmailLearningRule;
use App\Models\IncomingEmailMessage;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Persistent operator-taught rules. Evaluated in the processor BEFORE any AI step.
 * AI never invents rules — operators confirm suggestions and rules are saved here.
 */
class IncomingEmailLearningRulesService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * @return list<IncomingEmailLearningRuleMatch>
     */
    public function matchesFor(IncomingEmailMessage $message): array
    {
        $candidates = $this->candidateValues($message);

        if ($candidates === []) {
            return [];
        }

        /** @var Collection<int, IncomingEmailLearningRule> $rules */
        $rules = IncomingEmailLearningRule::query()
            ->enabled()
            ->where(function ($query) use ($candidates): void {
                foreach ($candidates as $index => [$type, $value]) {
                    $method = $index === 0 ? 'where' : 'orWhere';
                    $query->{$method}(function ($nested) use ($type, $value): void {
                        $nested->where('rule_type', $type->value)
                            ->where('match_value', $value);
                    });
                }
            })
            ->orderByDesc('confidence')
            ->orderByDesc('times_used')
            ->orderBy('id')
            ->get();

        $matches = [];

        foreach ($rules as $rule) {
            $matchedValue = $this->matchedValueForRule($rule, $candidates);

            if ($matchedValue === null) {
                continue;
            }

            $matches[] = new IncomingEmailLearningRuleMatch(
                rule: $rule,
                matchedOn: $rule->rule_type->label(),
                matchedValue: $matchedValue,
            );
        }

        return $matches;
    }

    /**
     * Apply matching learning rules before AI / deterministic classifier.
     */
    public function applyBeforeIntelligence(
        IncomingEmailMessage $message,
        User $actor,
    ): IncomingEmailLearningApplicationResult {
        $matches = $this->matchesFor($message);

        if ($matches === []) {
            return IncomingEmailLearningApplicationResult::none();
        }

        $stop = false;
        $applied = [];
        $classificationOverride = null;
        $importanceOverride = null;
        $assigneeUserId = null;

        foreach ($matches as $match) {
            $this->applyMatch($message, $match, $actor);
            $applied[] = [
                'rule_id' => $match->rule->id,
                'rule_type' => $match->rule->rule_type->value,
                'match_value' => $match->matchedValue,
                'decision_type' => $match->rule->decision_type->value,
                'decision_value' => $match->rule->decision_value,
                'confidence' => $match->rule->confidence,
            ];

            $match->rule->recordUsage();

            if ($match->rule->decision_type === IncomingEmailLearningDecisionType::Classification) {
                $operator = IncomingEmailOperatorClassification::tryFrom($match->rule->decision_value);
                $classificationOverride = $operator?->toStoredClassification() ?? $classificationOverride;
            }

            if ($match->rule->decision_type === IncomingEmailLearningDecisionType::Importance) {
                $importanceOverride = IncomingEmailImportance::tryFrom($match->rule->decision_value)
                    ?? $importanceOverride;
            }

            if ($match->rule->decision_type === IncomingEmailLearningDecisionType::Assign) {
                $assigneeUserId = (int) $match->rule->decision_value ?: $assigneeUserId;
            }

            if ($match->rule->decision_type === IncomingEmailLearningDecisionType::Ignore) {
                $stop = true;
                break;
            }
        }

        $this->auditLogService->log(
            userId: $actor->id,
            event: 'incoming_email.learning_rule_applied',
            auditable: $message->fresh(),
            newValues: [
                'rules' => $applied,
                'stopped' => $stop,
            ],
        );

        return new IncomingEmailLearningApplicationResult(
            stopProcessing: $stop,
            applied: true,
            classificationOverride: $classificationOverride,
            importanceOverride: $importanceOverride,
            assigneeUserId: $assigneeUserId,
        );
    }

    public function upsertFromOperatorTeaching(
        IncomingEmailMessage $message,
        IncomingEmailLearningScope $scope,
        IncomingEmailLearningDecisionType $decisionType,
        string $decisionValue,
        User $actor,
        int $confidence = 90,
    ): ?IncomingEmailLearningRule {
        if (! $scope->createsPersistentRule()) {
            return null;
        }

        $ruleType = $scope->toRuleType();

        if ($ruleType === null) {
            return null;
        }

        $matchValue = $this->matchValueForScope($message, $scope);

        if ($matchValue === null || $matchValue === '') {
            return null;
        }

        $rule = IncomingEmailLearningRule::query()->updateOrCreate(
            [
                'rule_type' => $ruleType->value,
                'match_value' => $matchValue,
                'decision_type' => $decisionType->value,
            ],
            [
                'decision_value' => $decisionValue,
                'confidence' => max(1, min(100, $confidence)),
                'created_by' => $actor->id,
                'enabled' => true,
            ],
        );

        $this->auditLogService->log(
            userId: $actor->id,
            event: 'incoming_email.learning_rule_saved',
            auditable: $rule,
            newValues: [
                'rule_type' => $rule->rule_type->value,
                'match_value' => $rule->match_value,
                'decision_type' => $rule->decision_type->value,
                'decision_value' => $rule->decision_value,
                'confidence' => $rule->confidence,
                'source_message_id' => $message->id,
                'scope' => $scope->value,
            ],
        );

        return $rule->fresh();
    }

    public function normalizeSubjectPattern(?string $subject): string
    {
        $value = Str::lower(trim((string) $subject));
        $value = preg_replace('/^(re|fwd|fw)\s*:\s*/i', '', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        $value = preg_replace('/\d+/', '#', $value) ?? $value;

        return Str::limit(trim($value), 200, '');
    }

    public function senderDomain(?string $email): ?string
    {
        $email = Str::lower(trim((string) $email));

        if ($email === '' || ! str_contains($email, '@')) {
            return null;
        }

        return Str::after($email, '@') ?: null;
    }

    /**
     * @return list<array{0: IncomingEmailLearningRuleType, 1: string}>
     */
    private function candidateValues(IncomingEmailMessage $message): array
    {
        $candidates = [];

        $sender = Str::lower(trim((string) $message->from_email));

        if ($sender !== '') {
            $candidates[] = [IncomingEmailLearningRuleType::Sender, $sender];
        }

        $domain = $this->senderDomain($message->from_email);

        if ($domain !== null) {
            $candidates[] = [IncomingEmailLearningRuleType::SenderDomain, $domain];
        }

        $subjectPattern = $this->normalizeSubjectPattern($message->subject);

        if ($subjectPattern !== '') {
            $candidates[] = [IncomingEmailLearningRuleType::SubjectPattern, $subjectPattern];
        }

        $mailbox = Str::lower(trim((string) $message->mailbox));

        if ($mailbox !== '') {
            $candidates[] = [IncomingEmailLearningRuleType::Mailbox, $mailbox];
        }

        $haystack = Str::lower(trim(
            ((string) $message->subject).' '.((string) $message->preview),
        ));

        if ($haystack !== '') {
            $keywordRules = IncomingEmailLearningRule::query()
                ->enabled()
                ->where('rule_type', IncomingEmailLearningRuleType::Keyword->value)
                ->get(['id', 'rule_type', 'match_value']);

            foreach ($keywordRules as $keywordRule) {
                $keyword = Str::lower(trim((string) $keywordRule->match_value));

                if ($keyword !== '' && str_contains($haystack, $keyword)) {
                    $candidates[] = [IncomingEmailLearningRuleType::Keyword, $keyword];
                }
            }
        }

        return $candidates;
    }

    /**
     * @param  list<array{0: IncomingEmailLearningRuleType, 1: string}>  $candidates
     */
    private function matchedValueForRule(IncomingEmailLearningRule $rule, array $candidates): ?string
    {
        foreach ($candidates as [$type, $value]) {
            if ($type === $rule->rule_type && $value === $rule->match_value) {
                return $value;
            }
        }

        return null;
    }

    private function matchValueForScope(
        IncomingEmailMessage $message,
        IncomingEmailLearningScope $scope,
    ): ?string {
        return match ($scope) {
            IncomingEmailLearningScope::ThisEmail => null,
            IncomingEmailLearningScope::SameSender => Str::lower(trim((string) $message->from_email)) ?: null,
            IncomingEmailLearningScope::SameDomain => $this->senderDomain($message->from_email),
            IncomingEmailLearningScope::SameSubjectPattern => $this->normalizeSubjectPattern($message->subject) ?: null,
            IncomingEmailLearningScope::Always => Str::lower(trim((string) $message->mailbox)) ?: null,
        };
    }

    private function applyMatch(
        IncomingEmailMessage $message,
        IncomingEmailLearningRuleMatch $match,
        User $actor,
    ): void {
        $rule = $match->rule;
        $explanation = [
            'why' => 'Matched an operator-confirmed learning rule.',
            'examples' => [
                'Rule type: '.$rule->rule_type->label(),
                'Decision: '.$rule->decision_type->label(),
            ],
            'matched_sender' => $message->from_email,
            'matched_keyword' => $rule->rule_type === IncomingEmailLearningRuleType::Keyword
                ? $rule->match_value
                : null,
            'matched_on' => $match->matchedOn,
            'matched_value' => $match->matchedValue,
            'previous_operator_confirmation' => true,
            'rule_confidence' => $rule->confidence,
            'rule_id' => $rule->id,
        ];

        $attributes = [
            'matched_learning_rule_id' => $rule->id,
            'ira_decision' => $this->decisionLabel($rule),
            'ira_confidence' => $rule->confidence,
            'ira_reason' => 'Learning rule: '.$rule->rule_type->label().' → '.$rule->decision_type->label(),
            'ira_explanation' => $explanation,
        ];

        match ($rule->decision_type) {
            IncomingEmailLearningDecisionType::Assign => $this->applyAssign($message, $rule, $attributes),
            IncomingEmailLearningDecisionType::Classification => $this->applyClassification($message, $rule, $attributes),
            IncomingEmailLearningDecisionType::Importance => $this->applyImportance($message, $rule, $attributes),
            IncomingEmailLearningDecisionType::Ignore => $this->applyIgnore($message, $rule, $attributes, $actor),
        };
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function applyAssign(
        IncomingEmailMessage $message,
        IncomingEmailLearningRule $rule,
        array $attributes,
    ): void {
        $userId = (int) $rule->decision_value;

        if ($userId <= 0) {
            return;
        }

        $message->update(array_merge($attributes, [
            'learning_owner_user_id' => $userId,
            'suggested_assignee_user_id' => $userId,
        ]));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function applyClassification(
        IncomingEmailMessage $message,
        IncomingEmailLearningRule $rule,
        array $attributes,
    ): void {
        $operator = IncomingEmailOperatorClassification::tryFrom($rule->decision_value);

        if ($operator === null) {
            return;
        }

        $message->update(array_merge($attributes, [
            'classification' => $operator->toStoredClassification(),
        ]));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function applyImportance(
        IncomingEmailMessage $message,
        IncomingEmailLearningRule $rule,
        array $attributes,
    ): void {
        $importance = IncomingEmailImportance::tryFrom($rule->decision_value);

        if ($importance === null) {
            return;
        }

        $message->update(array_merge($attributes, [
            'importance' => $importance,
        ]));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function applyIgnore(
        IncomingEmailMessage $message,
        IncomingEmailLearningRule $rule,
        array $attributes,
        User $actor,
    ): void {
        $ignoreAction = IncomingEmailIgnoreLearningAction::tryFrom($rule->decision_value)
            ?? IncomingEmailIgnoreLearningAction::AlwaysIgnore;

        $reason = $ignoreAction->ignoreReason();
        $classification = $ignoreAction->toStoredClassification();

        $message->update(array_merge($attributes, [
            'status' => IncomingEmailMessageStatus::Ignored,
            'ignore_reason' => $reason,
            'classification' => $classification,
            'processed_at' => now(),
            'processing_error' => null,
        ]));

        IncomingEmailIgnoreStat::incrementReason($reason);

        $this->auditLogService->log(
            userId: $actor->id,
            event: 'incoming_email.ignored',
            auditable: $message->fresh(),
            newValues: [
                'reason' => $reason,
                'classification' => $classification->value,
                'source' => 'learning_rule',
                'rule_id' => $rule->id,
            ],
        );
    }

    private function decisionLabel(IncomingEmailLearningRule $rule): string
    {
        return match ($rule->decision_type) {
            IncomingEmailLearningDecisionType::Assign => 'Assign to teammate',
            IncomingEmailLearningDecisionType::Classification => IncomingEmailOperatorClassification::tryFrom($rule->decision_value)?->label()
                ?? 'Classification',
            IncomingEmailLearningDecisionType::Importance => IncomingEmailImportance::tryFrom($rule->decision_value)?->label()
                ?? 'Importance',
            IncomingEmailLearningDecisionType::Ignore => IncomingEmailIgnoreLearningAction::tryFrom($rule->decision_value)?->label()
                ?? 'Ignore',
        };
    }
}
