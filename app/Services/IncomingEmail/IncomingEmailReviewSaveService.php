<?php

namespace App\Services\IncomingEmail;

use App\Enums\IncomingEmailDisposition;
use App\Enums\IncomingEmailIgnoreDispositionVariant;
use App\Enums\IncomingEmailImportance;
use App\Enums\IncomingEmailKeepPendingReason;
use App\Enums\IncomingEmailLearningScope;
use App\Enums\IncomingEmailOperatorClassification;
use App\Models\IncomingEmailMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * UX orchestration for Learning Center review Save.
 *
 * Internally keeps Teaching, IRA Memory, Disposition, and Audit separate —
 * this only sequences them in one DB transaction for a single operator submit.
 */
class IncomingEmailReviewSaveService
{
    public function __construct(
        private readonly IncomingEmailLearningActionService $learningActions,
        private readonly IncomingEmailDispositionService $dispositions,
    ) {}

    /**
     * @param  list<int>  $messageIds
     * @param  array{
     *     disposition: IncomingEmailDisposition,
     *     scope?: IncomingEmailLearningScope|null,
     *     assignee_user_id?: int|null,
     *     baseline_assignee_user_id?: int|null,
     *     classification?: IncomingEmailOperatorClassification|null,
     *     baseline_classification?: IncomingEmailOperatorClassification|null,
     *     importance?: IncomingEmailImportance|null,
     *     baseline_importance?: IncomingEmailImportance|null,
     *     case_reference?: string|null,
     *     ignore_variant?: IncomingEmailIgnoreDispositionVariant|null,
     *     keep_pending_reason?: IncomingEmailKeepPendingReason|null,
     *     disposition_assignee_user_id?: int|null,
     * }  $input
     * @return array{
     *     taught: bool,
     *     rules_saved: int,
     *     teach_actions: list<string>,
     *     disposition: string,
     *     applied: int,
     *     cases: list<string>
     * }
     */
    public function save(array $messageIds, array $input, User $actor): array
    {
        $messageIds = array_values(array_unique(array_filter(array_map('intval', $messageIds))));

        if ($messageIds === []) {
            throw ValidationException::withMessages([
                'message_ids' => 'Select an email to review.',
            ]);
        }

        /** @var IncomingEmailDisposition $disposition */
        $disposition = $input['disposition'];

        return DB::transaction(function () use ($messageIds, $input, $actor, $disposition): array {
            $teachResult = $this->applyTeachIfChanged($messageIds, $input, $actor);

            $dispositionResult = $this->applyDisposition($messageIds, $input, $actor, $disposition);

            return [
                'taught' => $teachResult['taught'],
                'rules_saved' => $teachResult['rules_saved'],
                'teach_actions' => $teachResult['actions'],
                'disposition' => $disposition->value,
                'applied' => (int) ($dispositionResult['applied'] ?? 0),
                'cases' => $dispositionResult['cases'] ?? [],
            ];
        });
    }

    /**
     * @param  list<int>  $messageIds
     * @param  array<string, mixed>  $input
     * @return array{taught: bool, rules_saved: int, actions: list<string>}
     */
    private function applyTeachIfChanged(array $messageIds, array $input, User $actor): array
    {
        $actions = [];
        $rulesSaved = 0;

        $scope = $input['scope'] ?? null;
        $assigneeId = $this->nullableInt($input['assignee_user_id'] ?? null);
        $baselineAssigneeId = $this->nullableInt($input['baseline_assignee_user_id'] ?? null);
        $classification = $input['classification'] ?? null;
        $baselineClassification = $input['baseline_classification'] ?? null;
        $importance = $input['importance'] ?? null;
        $baselineImportance = $input['baseline_importance'] ?? null;

        $ownerChanged = $assigneeId !== null && $assigneeId !== $baselineAssigneeId;
        $classificationChanged = $classification instanceof IncomingEmailOperatorClassification
            && (
                ! $baselineClassification instanceof IncomingEmailOperatorClassification
                || $classification !== $baselineClassification
            );
        $importanceChanged = $importance instanceof IncomingEmailImportance
            && (
                ! $baselineImportance instanceof IncomingEmailImportance
                || $importance !== $baselineImportance
            );

        if (! $ownerChanged && ! $classificationChanged && ! $importanceChanged) {
            return [
                'taught' => false,
                'rules_saved' => 0,
                'actions' => [],
            ];
        }

        if (! $scope instanceof IncomingEmailLearningScope) {
            throw ValidationException::withMessages([
                'scope' => 'Choose a learning scope when teaching IRA.',
            ]);
        }

        // Prefer current row baselines when bulk is not used — still validate messages exist.
        IncomingEmailMessage::query()->whereIn('id', $messageIds)->get();

        if ($ownerChanged) {
            $result = $this->learningActions->applyAssign(
                messageIds: $messageIds,
                assigneeUserId: $assigneeId,
                scope: $scope,
                actor: $actor,
            );
            $rulesSaved += (int) $result['rules_saved'];
            $actions[] = 'assign';
        }

        if ($classificationChanged) {
            $result = $this->learningActions->applyClassification(
                messageIds: $messageIds,
                classification: $classification,
                scope: $scope,
                actor: $actor,
            );
            $rulesSaved += (int) $result['rules_saved'];
            $actions[] = 'classification';
        }

        if ($importanceChanged) {
            $result = $this->learningActions->applyImportance(
                messageIds: $messageIds,
                importance: $importance,
                scope: $scope,
                actor: $actor,
            );
            $rulesSaved += (int) $result['rules_saved'];
            $actions[] = 'importance';
        }

        return [
            'taught' => $actions !== [],
            'rules_saved' => $rulesSaved,
            'actions' => $actions,
        ];
    }

    /**
     * @param  list<int>  $messageIds
     * @param  array<string, mixed>  $input
     * @return array{applied: int, cases?: list<string>}
     */
    private function applyDisposition(
        array $messageIds,
        array $input,
        User $actor,
        IncomingEmailDisposition $disposition,
    ): array {
        $dispositionAssigneeId = $this->nullableInt($input['disposition_assignee_user_id'] ?? null)
            ?? $this->nullableInt($input['assignee_user_id'] ?? null);

        return match ($disposition) {
            IncomingEmailDisposition::CreateCase => $this->dispositions->createServiceCase(
                messageIds: $messageIds,
                actor: $actor,
                assigneeUserId: $dispositionAssigneeId,
            ),
            IncomingEmailDisposition::LinkCase => $this->dispositions->linkExistingCase(
                messageIds: $messageIds,
                caseReference: (string) ($input['case_reference'] ?? ''),
                actor: $actor,
                assigneeUserId: $dispositionAssigneeId,
            ),
            IncomingEmailDisposition::Ignore => $this->dispositions->ignore(
                messageIds: $messageIds,
                variant: $input['ignore_variant'] instanceof IncomingEmailIgnoreDispositionVariant
                    ? $input['ignore_variant']
                    : throw ValidationException::withMessages([
                        'ignore_variant' => 'Choose how to ignore this email.',
                    ]),
                actor: $actor,
            ),
            IncomingEmailDisposition::Spam => $this->dispositions->spam($messageIds, $actor),
            IncomingEmailDisposition::Promotion => $this->dispositions->promotion($messageIds, $actor),
            IncomingEmailDisposition::AutoProcessed => $this->dispositions->autoProcessed($messageIds, $actor),
            IncomingEmailDisposition::KeepPending => $this->dispositions->keepPending(
                messageIds: $messageIds,
                reason: $input['keep_pending_reason'] instanceof IncomingEmailKeepPendingReason
                    ? $input['keep_pending_reason']
                    : throw ValidationException::withMessages([
                        'keep_pending_reason' => 'Choose why this email stays pending.',
                    ]),
                actor: $actor,
            ),
        };
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
