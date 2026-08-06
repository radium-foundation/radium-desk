<?php

namespace App\Services\IncomingEmail;

use App\Enums\AssignmentOrigin;
use App\Enums\IncomingEmailClassification;
use App\Enums\IncomingEmailDisposition;
use App\Enums\IncomingEmailIgnoreDispositionVariant;
use App\Enums\IncomingEmailIgnoreLearningAction;
use App\Enums\IncomingEmailKeepPendingReason;
use App\Enums\IncomingEmailLearningDecisionType;
use App\Enums\IncomingEmailLearningScope;
use App\Enums\IncomingEmailMessageStatus;
use App\Enums\IraMemoryCreatedFrom;
use App\Models\Incident;
use App\Models\IncomingEmailIgnoreStat;
use App\Models\IncomingEmailLearningRule;
use App\Models\IncomingEmailMessage;
use App\Models\Order;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\ServiceCaseAssignmentService;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Operator disposition for Needs Human emails — separate from IRA teaching.
 *
 * Teaching may annotate classification / owner / rules without clearing the queue.
 * Disposition is required to leave Needs Human (except Keep Pending).
 */
class IncomingEmailDispositionService
{
    public function __construct(
        private readonly IncomingEmailServiceCaseCreateService $serviceCaseCreateService,
        private readonly IncomingEmailLinkService $linkService,
        private readonly IncomingEmailAssignmentService $emailAssignmentService,
        private readonly ServiceCaseAssignmentService $caseAssignmentService,
        private readonly IncomingEmailServiceCaseCategoryMapper $categoryMapper,
        private readonly IncomingEmailLearningRulesService $learningRulesService,
        private readonly IncomingEmailIntakeCounterService $intakeCounterService,
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * @param  list<int>  $messageIds
     * @return array{applied: int, cases: list<string>}
     */
    public function createServiceCase(
        array $messageIds,
        User $actor,
        ?int $assigneeUserId = null,
    ): array {
        $messages = $this->pendingMessages($messageIds);
        $cases = [];

        foreach ($messages as $message) {
            $classification = $this->customerFacingClassification($message);
            $assignee = $this->resolveAssignee($message, $assigneeUserId);

            if ($message->order_id !== null) {
                $order = Order::query()->whereKey($message->order_id)->firstOrFail();
                $result = $this->serviceCaseCreateService->createLinkAndRouteForOrder(
                    order: $order,
                    message: $message->fresh(),
                    actor: $actor,
                    classification: $classification,
                    skipAssignment: $assignee !== null,
                );
            } else {
                $result = $this->serviceCaseCreateService->createLinkAndRouteForUnknownCustomer(
                    message: $message->fresh(),
                    actor: $actor,
                    classification: $classification,
                    skipAssignment: $assignee !== null,
                );
            }

            $incident = $result['incident'];

            if ($assignee !== null) {
                if ($incident->assigned_to_user_id === null) {
                    $incident = $this->caseAssignmentService->assignWithAuditContext(
                        incident: $incident,
                        assignee: $assignee,
                        actor: $actor,
                        auditContext: [
                            'source' => 'email_intake_disposition',
                            'disposition' => IncomingEmailDisposition::CreateCase->value,
                            'incoming_email_message_id' => $message->id,
                        ],
                        event: 'service_case.assigned',
                        assignmentOrigin: AssignmentOrigin::Manual,
                    );
                } elseif ((int) $incident->assigned_to_user_id !== (int) $assignee->id) {
                    $incident = $this->caseAssignmentService->reassign($incident, $assignee, $actor);
                }

                $this->emailAssignmentService->notifyOwnerOfNewEmail(
                    $incident->fresh(['assignee']),
                    $result['message']->fresh(),
                );
            }

            $this->markDisposition(
                message: $result['message']->fresh(),
                disposition: IncomingEmailDisposition::CreateCase,
                actor: $actor,
                extra: [
                    'incident_id' => $incident->id,
                    'reference_no' => $incident->reference_no,
                    'assignee_user_id' => $incident->fresh()->assigned_to_user_id,
                ],
            );

            $cases[] = (string) ($incident->reference_no ?? $incident->id);
        }

        $this->intakeCounterService->forgetDashboardWidgetCache();

        return ['applied' => $messages->count(), 'cases' => $cases];
    }

    /**
     * @param  list<int>  $messageIds
     * @return array{applied: int, cases: list<string>}
     */
    public function linkExistingCase(
        array $messageIds,
        string $caseReference,
        User $actor,
        ?int $assigneeUserId = null,
    ): array {
        $incident = $this->resolveIncidentByReference($caseReference);
        $messages = $this->pendingMessages($messageIds);
        $cases = [];
        $assignee = $assigneeUserId !== null
            ? User::query()->whereKey($assigneeUserId)->where('is_active', true)->first()
            : null;

        foreach ($messages as $message) {
            $classification = $this->customerFacingClassification($message);

            $linked = $this->linkService->link(
                $incident,
                $message->fresh(),
                $actor,
                $classification,
            );

            $freshIncident = $incident->fresh(['assignee', 'order']);

            if ($assignee !== null) {
                if ($freshIncident->assigned_to_user_id === null) {
                    $freshIncident = $this->caseAssignmentService->assignWithAuditContext(
                        incident: $freshIncident,
                        assignee: $assignee,
                        actor: $actor,
                        auditContext: [
                            'source' => 'email_intake_disposition',
                            'disposition' => IncomingEmailDisposition::LinkCase->value,
                            'incoming_email_message_id' => $message->id,
                        ],
                        event: 'service_case.assigned',
                        assignmentOrigin: AssignmentOrigin::Manual,
                    );
                }
            } else {
                $freshIncident = $this->emailAssignmentService->routeLinkedEmail(
                    $freshIncident,
                    $linked->fresh(),
                    $actor,
                );
            }

            $this->markDisposition(
                message: $linked->fresh(),
                disposition: IncomingEmailDisposition::LinkCase,
                actor: $actor,
                extra: [
                    'incident_id' => $freshIncident->id,
                    'reference_no' => $freshIncident->reference_no,
                    'case_reference_input' => $caseReference,
                ],
            );

            $cases[] = (string) ($freshIncident->reference_no ?? $freshIncident->id);
        }

        $this->intakeCounterService->forgetDashboardWidgetCache();

        return ['applied' => $messages->count(), 'cases' => array_values(array_unique($cases))];
    }

    /**
     * @param  list<int>  $messageIds
     * @return array{applied: int, rules_saved: int}
     */
    public function ignore(
        array $messageIds,
        IncomingEmailIgnoreDispositionVariant $variant,
        User $actor,
    ): array {
        return $this->disposeAsIgnored(
            messageIds: $messageIds,
            disposition: IncomingEmailDisposition::Ignore,
            classification: IncomingEmailClassification::OtherIgnored,
            ignoreReason: $variant->ignoreReason(),
            actor: $actor,
            learningScope: $variant->learningScope(),
            persistRule: $variant->createsPersistentRule(),
            learningDecisionValue: IncomingEmailIgnoreLearningAction::AlwaysIgnore->value,
        );
    }

    /**
     * @param  list<int>  $messageIds
     * @return array{applied: int, rules_saved: int}
     */
    public function spam(array $messageIds, User $actor, bool $alwaysSender = false): array
    {
        return $this->disposeAsIgnored(
            messageIds: $messageIds,
            disposition: IncomingEmailDisposition::Spam,
            classification: IncomingEmailClassification::Spam,
            ignoreReason: 'spam',
            actor: $actor,
            learningScope: $alwaysSender
                ? IncomingEmailLearningScope::SameSender
                : IncomingEmailLearningScope::ThisEmail,
            persistRule: $alwaysSender,
            learningDecisionValue: IncomingEmailIgnoreLearningAction::AlwaysIgnore->value,
        );
    }

    /**
     * @param  list<int>  $messageIds
     * @return array{applied: int, rules_saved: int}
     */
    public function promotion(array $messageIds, User $actor, bool $alwaysSender = false): array
    {
        return $this->disposeAsIgnored(
            messageIds: $messageIds,
            disposition: IncomingEmailDisposition::Promotion,
            classification: IncomingEmailClassification::Promotional,
            ignoreReason: 'promotions',
            actor: $actor,
            learningScope: $alwaysSender
                ? IncomingEmailLearningScope::SameSender
                : IncomingEmailLearningScope::ThisEmail,
            persistRule: $alwaysSender,
            learningDecisionValue: IncomingEmailIgnoreLearningAction::Newsletter->value,
        );
    }

    /**
     * @param  list<int>  $messageIds
     * @return array{applied: int, rules_saved: int}
     */
    public function autoProcessed(array $messageIds, User $actor): array
    {
        return $this->disposeAsIgnored(
            messageIds: $messageIds,
            disposition: IncomingEmailDisposition::AutoProcessed,
            classification: IncomingEmailClassification::OtherIgnored,
            ignoreReason: 'auto_responder',
            actor: $actor,
            learningScope: IncomingEmailLearningScope::ThisEmail,
            persistRule: false,
            learningDecisionValue: IncomingEmailIgnoreLearningAction::IgnoreOnce->value,
        );
    }

    /**
     * @param  list<int>  $messageIds
     * @return array{applied: int}
     */
    public function keepPending(
        array $messageIds,
        IncomingEmailKeepPendingReason $reason,
        User $actor,
    ): array {
        $messages = $this->pendingMessages($messageIds);

        foreach ($messages as $message) {
            $message->update([
                'disposition' => IncomingEmailDisposition::KeepPending,
                'disposition_reason' => $reason->value,
                'disposed_at' => now(),
                'disposed_by_user_id' => $actor->id,
                // status remains needs_review / failed
            ]);

            $this->auditLogService->log(
                userId: $actor->id,
                event: 'incoming_email.disposition',
                auditable: $message->fresh(),
                newValues: [
                    'disposition' => IncomingEmailDisposition::KeepPending->value,
                    'disposition_reason' => $reason->value,
                    'completed' => false,
                    'completed_at' => null,
                    'completed_by' => $actor->id,
                ],
            );
        }

        $this->intakeCounterService->forgetDashboardWidgetCache();

        return ['applied' => $messages->count()];
    }

    /**
     * @param  list<int>  $messageIds
     * @return array{applied: int, rules_saved: int}
     */
    private function disposeAsIgnored(
        array $messageIds,
        IncomingEmailDisposition $disposition,
        IncomingEmailClassification $classification,
        string $ignoreReason,
        User $actor,
        IncomingEmailLearningScope $learningScope,
        bool $persistRule,
        string $learningDecisionValue,
    ): array {
        $messages = $this->pendingMessages($messageIds);
        $rulesSaved = 0;

        foreach ($messages as $message) {
            $message->update([
                'status' => IncomingEmailMessageStatus::Ignored,
                'ignore_reason' => $ignoreReason,
                'classification' => $classification,
                'processed_at' => now(),
                'processing_error' => null,
                'disposition' => $disposition,
                'disposition_reason' => null,
                'disposed_at' => now(),
                'disposed_by_user_id' => $actor->id,
            ]);

            IncomingEmailIgnoreStat::incrementReason($ignoreReason);

            $this->auditLogService->log(
                userId: $actor->id,
                event: 'incoming_email.ignored',
                auditable: $message->fresh(),
                newValues: [
                    'reason' => $ignoreReason,
                    'classification' => $classification->value,
                    'source' => 'email_intake_disposition',
                    'disposition' => $disposition->value,
                ],
            );

            $this->auditDispositionCompletion($message->fresh(), $disposition, $actor, [
                'ignore_reason' => $ignoreReason,
                'classification' => $classification->value,
            ]);

            if ($persistRule) {
                $rule = $this->learningRulesService->upsertFromOperatorTeaching(
                    message: $message->fresh(),
                    scope: $learningScope,
                    decisionType: IncomingEmailLearningDecisionType::Ignore,
                    decisionValue: $learningDecisionValue,
                    actor: $actor,
                    createdFrom: IraMemoryCreatedFrom::Disposition,
                );

                if ($rule instanceof IncomingEmailLearningRule) {
                    $rulesSaved++;
                    $message->update([
                        'matched_learning_rule_id' => $rule->id,
                        'matched_ira_memory_id' => $rule->id,
                    ]);
                }
            }
        }

        $this->intakeCounterService->forgetDashboardWidgetCache();

        return ['applied' => $messages->count(), 'rules_saved' => $rulesSaved];
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function markDisposition(
        IncomingEmailMessage $message,
        IncomingEmailDisposition $disposition,
        User $actor,
        array $extra = [],
    ): void {
        $message->update([
            'disposition' => $disposition,
            'disposition_reason' => null,
            'disposed_at' => now(),
            'disposed_by_user_id' => $actor->id,
        ]);

        $this->auditDispositionCompletion($message->fresh(), $disposition, $actor, $extra);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function auditDispositionCompletion(
        IncomingEmailMessage $message,
        IncomingEmailDisposition $disposition,
        User $actor,
        array $extra = [],
    ): void {
        $this->auditLogService->log(
            userId: $actor->id,
            event: 'incoming_email.disposition',
            auditable: $message,
            newValues: array_merge([
                'disposition' => $disposition->value,
                'disposition_reason' => $message->disposition_reason,
                'completed' => $disposition->leavesNeedsHuman(),
                'completed_at' => optional($message->disposed_at)->toIso8601String(),
                'completed_by' => $actor->id,
                'status' => $message->status?->value,
            ], $extra),
        );
    }

    private function customerFacingClassification(IncomingEmailMessage $message): IncomingEmailClassification
    {
        $classification = $message->classification ?? IncomingEmailClassification::UnknownCustomer;

        if (
            $classification->isOperational()
            && ! $this->categoryMapper->isInternalOperational($classification)
        ) {
            return $classification;
        }

        return IncomingEmailClassification::UnknownCustomer;
    }

    private function resolveAssignee(IncomingEmailMessage $message, ?int $assigneeUserId): ?User
    {
        $id = $assigneeUserId
            ?? $message->learning_owner_user_id
            ?? $message->suggested_assignee_user_id;

        if ($id === null) {
            return null;
        }

        return User::query()->whereKey($id)->where('is_active', true)->first();
    }

    private function resolveIncidentByReference(string $caseReference): Incident
    {
        $sequence = Incident::parseReferenceSequence($caseReference);

        if ($sequence === null || $sequence <= 0) {
            throw ValidationException::withMessages([
                'case_reference' => 'Enter a valid service case number (e.g. SC27794).',
            ]);
        }

        $variants = Incident::referenceMatchVariants($sequence);

        $incident = Incident::query()
            ->whereIn('reference_no', $variants)
            ->orderByDesc('id')
            ->first();

        if ($incident === null) {
            throw ValidationException::withMessages([
                'case_reference' => 'No service case found for '.$caseReference.'.',
            ]);
        }

        return $incident;
    }

    /**
     * @param  list<int>  $messageIds
     * @return Collection<int, IncomingEmailMessage>
     */
    private function pendingMessages(array $messageIds): Collection
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
                'message_ids' => 'No Needs Human emails were selected.',
            ]);
        }

        return $messages;
    }
}
