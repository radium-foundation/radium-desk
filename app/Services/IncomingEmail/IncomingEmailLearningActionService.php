<?php

namespace App\Services\IncomingEmail;

use App\Enums\IncomingEmailIgnoreLearningAction;
use App\Enums\IncomingEmailImportance;
use App\Enums\IncomingEmailLearningDecisionType;
use App\Enums\IncomingEmailLearningScope;
use App\Enums\IncomingEmailMessageStatus;
use App\Enums\IncomingEmailOperatorClassification;
use App\Models\IncomingEmailIgnoreStat;
use App\Models\IncomingEmailLearningRule;
use App\Models\IncomingEmailMessage;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class IncomingEmailLearningActionService
{
    public function __construct(
        private readonly IncomingEmailLearningRulesService $learningRulesService,
        private readonly IncomingEmailIntakeCounterService $intakeCounterService,
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * @param  list<int>  $messageIds
     * @return array{applied: int, rules_saved: int}
     */
    public function applyAssign(
        array $messageIds,
        int $assigneeUserId,
        IncomingEmailLearningScope $scope,
        User $actor,
    ): array {
        $assignee = User::query()->whereKey($assigneeUserId)->where('is_active', true)->first();

        if ($assignee === null) {
            throw ValidationException::withMessages([
                'assignee_user_id' => 'Select a valid assignee.',
            ]);
        }

        return $this->applyToMessages(
            messageIds: $messageIds,
            actor: $actor,
            decisionType: IncomingEmailLearningDecisionType::Assign,
            decisionValue: (string) $assignee->id,
            scope: $scope,
            mutator: function (IncomingEmailMessage $message) use ($assignee): void {
                $message->update([
                    'learning_owner_user_id' => $assignee->id,
                    'suggested_assignee_user_id' => $assignee->id,
                    'ira_decision' => 'Assign to '.$assignee->name,
                    'ira_confidence' => 100,
                    'ira_reason' => 'Operator assigned this email.',
                    'ira_explanation' => [
                        'why' => 'Operator confirmed assignment.',
                        'examples' => ['Assigned to '.$assignee->name],
                        'matched_sender' => $message->from_email,
                        'matched_keyword' => null,
                        'previous_operator_confirmation' => true,
                        'rule_confidence' => 100,
                    ],
                ]);
            },
        );
    }

    /**
     * @param  list<int>  $messageIds
     * @return array{applied: int, rules_saved: int}
     */
    public function applyClassification(
        array $messageIds,
        IncomingEmailOperatorClassification $classification,
        IncomingEmailLearningScope $scope,
        User $actor,
    ): array {
        return $this->applyToMessages(
            messageIds: $messageIds,
            actor: $actor,
            decisionType: IncomingEmailLearningDecisionType::Classification,
            decisionValue: $classification->value,
            scope: $scope,
            mutator: function (IncomingEmailMessage $message) use ($classification): void {
                $stored = $classification->toStoredClassification();
                $shouldIgnore = in_array($classification, [
                    IncomingEmailOperatorClassification::Promotion,
                    IncomingEmailOperatorClassification::Spam,
                    IncomingEmailOperatorClassification::Automatic,
                ], true);

                $attributes = [
                    'classification' => $stored,
                    'ira_decision' => $classification->label(),
                    'ira_confidence' => 100,
                    'ira_reason' => 'Operator classified as '.$classification->label().'.',
                    'ira_explanation' => [
                        'why' => 'Operator confirmed classification.',
                        'examples' => ['Classified as '.$classification->label()],
                        'matched_sender' => $message->from_email,
                        'matched_keyword' => null,
                        'previous_operator_confirmation' => true,
                        'rule_confidence' => 100,
                    ],
                ];

                if ($shouldIgnore) {
                    $reason = match ($classification) {
                        IncomingEmailOperatorClassification::Promotion => 'promotions',
                        IncomingEmailOperatorClassification::Spam => 'spam',
                        IncomingEmailOperatorClassification::Automatic => 'auto_responder',
                        default => 'operator_classification',
                    };

                    $attributes['status'] = IncomingEmailMessageStatus::Ignored;
                    $attributes['ignore_reason'] = $reason;
                    $attributes['processed_at'] = now();
                    $attributes['processing_error'] = null;

                    IncomingEmailIgnoreStat::incrementReason($reason);
                }

                $message->update($attributes);
            },
        );
    }

    /**
     * @param  list<int>  $messageIds
     * @return array{applied: int, rules_saved: int}
     */
    public function applyImportance(
        array $messageIds,
        IncomingEmailImportance $importance,
        IncomingEmailLearningScope $scope,
        User $actor,
    ): array {
        return $this->applyToMessages(
            messageIds: $messageIds,
            actor: $actor,
            decisionType: IncomingEmailLearningDecisionType::Importance,
            decisionValue: $importance->value,
            scope: $scope,
            mutator: function (IncomingEmailMessage $message) use ($importance): void {
                $message->update([
                    'importance' => $importance,
                    'ira_decision' => $importance->label().' importance',
                    'ira_confidence' => 100,
                    'ira_reason' => 'Operator set importance to '.$importance->label().'.',
                    'ira_explanation' => [
                        'why' => 'Operator confirmed importance.',
                        'examples' => ['Importance: '.$importance->label()],
                        'matched_sender' => $message->from_email,
                        'matched_keyword' => null,
                        'previous_operator_confirmation' => true,
                        'rule_confidence' => 100,
                    ],
                ]);
            },
        );
    }

    /**
     * @param  list<int>  $messageIds
     * @return array{applied: int, rules_saved: int}
     */
    public function applyIgnore(
        array $messageIds,
        IncomingEmailIgnoreLearningAction $ignoreAction,
        IncomingEmailLearningScope $scope,
        User $actor,
    ): array {
        // Ignore once never persists a rule regardless of scope selection.
        $effectiveScope = $ignoreAction->createsPersistentRule()
            ? $scope
            : IncomingEmailLearningScope::ThisEmail;

        return $this->applyToMessages(
            messageIds: $messageIds,
            actor: $actor,
            decisionType: IncomingEmailLearningDecisionType::Ignore,
            decisionValue: $ignoreAction->value,
            scope: $effectiveScope,
            mutator: function (IncomingEmailMessage $message) use ($ignoreAction, $actor): void {
                $reason = $ignoreAction->ignoreReason();
                $classification = $ignoreAction->toStoredClassification();

                $message->update([
                    'status' => IncomingEmailMessageStatus::Ignored,
                    'ignore_reason' => $reason,
                    'classification' => $classification,
                    'processed_at' => now(),
                    'processing_error' => null,
                    'ira_decision' => $ignoreAction->label(),
                    'ira_confidence' => 100,
                    'ira_reason' => 'Operator chose '.$ignoreAction->label().'.',
                    'ira_explanation' => [
                        'why' => 'Operator confirmed ignore action.',
                        'examples' => [$ignoreAction->label()],
                        'matched_sender' => $message->from_email,
                        'matched_keyword' => null,
                        'previous_operator_confirmation' => true,
                        'rule_confidence' => 100,
                    ],
                ]);

                IncomingEmailIgnoreStat::incrementReason($reason);

                $this->auditLogService->log(
                    userId: $actor->id,
                    event: 'incoming_email.ignored',
                    auditable: $message->fresh(),
                    newValues: [
                        'reason' => $reason,
                        'classification' => $classification->value,
                        'source' => 'learning_center',
                        'ignore_action' => $ignoreAction->value,
                    ],
                );
            },
        );
    }

    /**
     * @param  list<int>  $messageIds
     * @param  callable(IncomingEmailMessage): void  $mutator
     * @return array{applied: int, rules_saved: int}
     */
    private function applyToMessages(
        array $messageIds,
        User $actor,
        IncomingEmailLearningDecisionType $decisionType,
        string $decisionValue,
        IncomingEmailLearningScope $scope,
        callable $mutator,
    ): array {
        $messages = $this->resolvableMessages($messageIds);
        $rulesSaved = 0;

        foreach ($messages as $message) {
            $mutator($message);

            $this->auditLogService->log(
                userId: $actor->id,
                event: 'incoming_email.learning_action',
                auditable: $message->fresh(),
                newValues: [
                    'decision_type' => $decisionType->value,
                    'decision_value' => $decisionValue,
                    'scope' => $scope->value,
                ],
            );

            $rule = $this->learningRulesService->upsertFromOperatorTeaching(
                message: $message->fresh(),
                scope: $scope,
                decisionType: $decisionType,
                decisionValue: $decisionValue,
                actor: $actor,
            );

            if ($rule instanceof IncomingEmailLearningRule) {
                $rulesSaved++;
                $message->update(['matched_learning_rule_id' => $rule->id]);
            }
        }

        $this->intakeCounterService->forgetDashboardWidgetCache();

        return [
            'applied' => $messages->count(),
            'rules_saved' => $rulesSaved,
        ];
    }

    /**
     * @param  list<int>  $messageIds
     * @return Collection<int, IncomingEmailMessage>
     */
    private function resolvableMessages(array $messageIds): Collection
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $messageIds))));

        if ($ids === []) {
            throw ValidationException::withMessages([
                'message_ids' => 'Select at least one email.',
            ]);
        }

        $messages = IncomingEmailMessage::query()
            ->whereIn('id', $ids)
            ->whereIn('status', [
                IncomingEmailMessageStatus::NeedsReview,
                IncomingEmailMessageStatus::Failed,
            ])
            ->get();

        if ($messages->isEmpty()) {
            throw ValidationException::withMessages([
                'message_ids' => 'No actionable emails were selected.',
            ]);
        }

        return $messages;
    }
}
