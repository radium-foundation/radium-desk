<?php

namespace App\Http\Controllers;

use App\Enums\IncomingEmailAutomaticSubcategory;
use App\Enums\IncomingEmailDisposition;
use App\Enums\IncomingEmailIgnoreDispositionVariant;
use App\Enums\IncomingEmailIgnoreLearningAction;
use App\Enums\IncomingEmailImportance;
use App\Enums\IncomingEmailIntakeQueue;
use App\Enums\IncomingEmailKeepPendingReason;
use App\Enums\IncomingEmailLearningScope;
use App\Enums\IncomingEmailOperatorClassification;
use App\Services\IncomingEmail\IncomingEmailDispositionService;
use App\Services\IncomingEmail\IncomingEmailIntakeCounterService;
use App\Services\IncomingEmail\IncomingEmailLearningActionService;
use App\Services\IncomingEmail\IncomingEmailLearningCenterPresenter;
use App\Services\IncomingEmail\IncomingEmailReviewSaveService;
use App\Services\ServiceCaseAssignmentService;
use App\Support\IncomingEmail\IncomingEmailAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class IncomingEmailAdminController extends Controller
{
    public function index(
        Request $request,
        IncomingEmailIntakeCounterService $counters,
        IncomingEmailLearningCenterPresenter $presenter,
        ServiceCaseAssignmentService $assignmentService,
    ): View {
        $this->authorizeEmailIntakeView($request);

        $queue = IncomingEmailIntakeQueue::tryFrom((string) $request->query('queue', ''))
            ?? IncomingEmailIntakeQueue::NeedsHuman;

        $subcategory = $queue === IncomingEmailIntakeQueue::Automatic
            ? IncomingEmailAutomaticSubcategory::tryFrom((string) $request->query('sub', ''))
            : null;

        $messages = $counters
            ->queryForQueue($queue, $subcategory)
            ->with([
                'order:id,customer_name,customer_email',
                'incident:id,reference_no,status',
                'learningOwner:id,name,first_name,last_name',
                'suggestedAssignee:id,name,first_name,last_name',
                'matchedLearningRule.creator:id,name,first_name,last_name',
                'disposedBy:id,name,first_name,last_name',
            ])
            ->limit(100)
            ->get();

        $counts = $counters->counts();

        return view('admin.incoming-emails.index', [
            'queue' => $queue,
            'subcategory' => $subcategory,
            'automaticBreakdown' => $queue === IncomingEmailIntakeQueue::Automatic
                ? $counters->automaticSubcategoryBreakdown($subcategory)
                : [],
            'messages' => $messages,
            'cards' => $presenter->cardsFor($messages, $queue),
            'counts' => $counts,
            'queues' => IncomingEmailIntakeQueue::cases(),
            'isLearningCenter' => true,
            'canManageEmailIntake' => IncomingEmailAccess::allowsManage($request->user()),
            'assignableUsers' => $assignmentService->reassignableAdmins(),
            'learningScopes' => IncomingEmailLearningScope::cases(),
            'operatorClassifications' => IncomingEmailOperatorClassification::teachingCases(),
            'importanceOptions' => IncomingEmailImportance::cases(),
            'ignoreActions' => IncomingEmailIgnoreLearningAction::cases(),
            'dispositions' => IncomingEmailDisposition::cases(),
            'ignoreDispositionVariants' => IncomingEmailIgnoreDispositionVariant::cases(),
            'keepPendingReasons' => IncomingEmailKeepPendingReason::cases(),
        ]);
    }

    public function applyLearning(
        Request $request,
        IncomingEmailLearningActionService $learningActions,
    ): RedirectResponse {
        $this->authorizeEmailIntakeManage($request);

        $action = (string) $request->input('action');

        $validated = $request->validate([
            'action' => ['required', Rule::in(['assign', 'classification', 'importance', 'ignore'])],
            'message_ids' => ['required', 'array', 'min:1'],
            'message_ids.*' => ['integer', 'distinct'],
            'scope' => ['required', Rule::enum(IncomingEmailLearningScope::class)],
            'return_queue' => ['nullable', Rule::enum(IncomingEmailIntakeQueue::class)],
            'assignee_user_id' => [
                Rule::requiredIf($action === 'assign'),
                'nullable',
                'integer',
                'exists:users,id',
            ],
            'classification' => [
                Rule::requiredIf($action === 'classification'),
                'nullable',
                Rule::enum(IncomingEmailOperatorClassification::class),
            ],
            'importance' => [
                Rule::requiredIf($action === 'importance'),
                'nullable',
                Rule::enum(IncomingEmailImportance::class),
            ],
            'ignore_action' => [
                Rule::requiredIf($action === 'ignore'),
                'nullable',
                Rule::enum(IncomingEmailIgnoreLearningAction::class),
            ],
        ]);

        $scope = IncomingEmailLearningScope::from($validated['scope']);
        $messageIds = array_map('intval', $validated['message_ids']);
        $actor = $request->user();

        $result = match ($validated['action']) {
            'assign' => $learningActions->applyAssign(
                messageIds: $messageIds,
                assigneeUserId: (int) $validated['assignee_user_id'],
                scope: $scope,
                actor: $actor,
            ),
            'classification' => $learningActions->applyClassification(
                messageIds: $messageIds,
                classification: IncomingEmailOperatorClassification::from((string) $validated['classification']),
                scope: $scope,
                actor: $actor,
            ),
            'importance' => $learningActions->applyImportance(
                messageIds: $messageIds,
                importance: IncomingEmailImportance::from((string) $validated['importance']),
                scope: $scope,
                actor: $actor,
            ),
            'ignore' => $learningActions->applyIgnore(
                messageIds: $messageIds,
                ignoreAction: IncomingEmailIgnoreLearningAction::from((string) $validated['ignore_action']),
                scope: $scope,
                actor: $actor,
            ),
        };

        $returnQueue = IncomingEmailIntakeQueue::tryFrom((string) ($validated['return_queue'] ?? ''))
            ?? IncomingEmailIntakeQueue::NeedsHuman;

        $status = $validated['action'] === 'ignore'
            ? sprintf(
                'Ignored %d email(s). Saved %d learning rule(s).',
                $result['applied'],
                $result['rules_saved'],
            )
            : sprintf(
                'Taught %d email(s). Saved %d learning rule(s). Disposition still required to leave Needs Human.',
                $result['applied'],
                $result['rules_saved'],
            );

        return redirect()
            ->route('admin.incoming-emails.index', [
                'queue' => $returnQueue->value,
            ])
            ->with('status', $status);
    }

    public function applyReview(
        Request $request,
        IncomingEmailReviewSaveService $reviewSave,
    ): RedirectResponse {
        $this->authorizeEmailIntakeManage($request);

        $disposition = (string) $request->input('disposition');
        $hasTeachChange = $this->reviewHasTeachChange($request);

        $validated = $request->validate([
            'message_ids' => ['required', 'array', 'min:1', 'max:1'],
            'message_ids.*' => ['integer', 'distinct'],
            'return_queue' => ['nullable', Rule::enum(IncomingEmailIntakeQueue::class)],
            'disposition' => ['required', Rule::enum(IncomingEmailDisposition::class)],
            'scope' => [
                Rule::requiredIf($hasTeachChange),
                'nullable',
                Rule::enum(IncomingEmailLearningScope::class),
            ],
            'assignee_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'baseline_assignee_user_id' => ['nullable', 'integer'],
            'classification' => ['nullable', Rule::enum(IncomingEmailOperatorClassification::class)],
            'baseline_classification' => ['nullable', Rule::enum(IncomingEmailOperatorClassification::class)],
            'importance' => ['nullable', Rule::enum(IncomingEmailImportance::class)],
            'baseline_importance' => ['nullable', Rule::enum(IncomingEmailImportance::class)],
            'disposition_assignee_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'case_reference' => [
                Rule::requiredIf($disposition === IncomingEmailDisposition::LinkCase->value),
                'nullable',
                'string',
                'max:32',
            ],
            'ignore_variant' => [
                Rule::requiredIf($disposition === IncomingEmailDisposition::Ignore->value),
                'nullable',
                Rule::enum(IncomingEmailIgnoreDispositionVariant::class),
            ],
            'keep_pending_reason' => [
                Rule::requiredIf($disposition === IncomingEmailDisposition::KeepPending->value),
                'nullable',
                Rule::enum(IncomingEmailKeepPendingReason::class),
            ],
        ]);

        $result = $reviewSave->save(
            messageIds: array_map('intval', $validated['message_ids']),
            input: [
                'disposition' => IncomingEmailDisposition::from($validated['disposition']),
                'scope' => isset($validated['scope'])
                    ? IncomingEmailLearningScope::from((string) $validated['scope'])
                    : null,
                'assignee_user_id' => isset($validated['assignee_user_id'])
                    ? (int) $validated['assignee_user_id']
                    : null,
                'baseline_assignee_user_id' => isset($validated['baseline_assignee_user_id'])
                    ? (int) $validated['baseline_assignee_user_id']
                    : null,
                'classification' => isset($validated['classification'])
                    ? IncomingEmailOperatorClassification::from((string) $validated['classification'])
                    : null,
                'baseline_classification' => isset($validated['baseline_classification'])
                    ? IncomingEmailOperatorClassification::from((string) $validated['baseline_classification'])
                    : null,
                'importance' => isset($validated['importance'])
                    ? IncomingEmailImportance::from((string) $validated['importance'])
                    : null,
                'baseline_importance' => isset($validated['baseline_importance'])
                    ? IncomingEmailImportance::from((string) $validated['baseline_importance'])
                    : null,
                'disposition_assignee_user_id' => isset($validated['disposition_assignee_user_id'])
                    ? (int) $validated['disposition_assignee_user_id']
                    : null,
                'case_reference' => $validated['case_reference'] ?? null,
                'ignore_variant' => isset($validated['ignore_variant'])
                    ? IncomingEmailIgnoreDispositionVariant::from((string) $validated['ignore_variant'])
                    : null,
                'keep_pending_reason' => isset($validated['keep_pending_reason'])
                    ? IncomingEmailKeepPendingReason::from((string) $validated['keep_pending_reason'])
                    : null,
            ],
            actor: $request->user(),
        );

        $returnQueue = IncomingEmailIntakeQueue::tryFrom((string) ($validated['return_queue'] ?? ''))
            ?? IncomingEmailIntakeQueue::NeedsHuman;

        $status = $this->reviewStatusMessage(
            IncomingEmailDisposition::from($validated['disposition']),
            $result,
            (string) ($validated['case_reference'] ?? ''),
        );

        return redirect()
            ->route('admin.incoming-emails.index', [
                'queue' => $returnQueue->value,
            ])
            ->with('status', $status);
    }

    public function applyDisposition(
        Request $request,
        IncomingEmailDispositionService $dispositions,
    ): RedirectResponse {
        $this->authorizeEmailIntakeManage($request);

        $disposition = (string) $request->input('disposition');

        $validated = $request->validate([
            'disposition' => ['required', Rule::enum(IncomingEmailDisposition::class)],
            'message_ids' => ['required', 'array', 'min:1'],
            'message_ids.*' => ['integer', 'distinct'],
            'return_queue' => ['nullable', Rule::enum(IncomingEmailIntakeQueue::class)],
            'assignee_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'case_reference' => [
                Rule::requiredIf($disposition === IncomingEmailDisposition::LinkCase->value),
                'nullable',
                'string',
                'max:32',
            ],
            'ignore_variant' => [
                Rule::requiredIf($disposition === IncomingEmailDisposition::Ignore->value),
                'nullable',
                Rule::enum(IncomingEmailIgnoreDispositionVariant::class),
            ],
            'keep_pending_reason' => [
                Rule::requiredIf($disposition === IncomingEmailDisposition::KeepPending->value),
                'nullable',
                Rule::enum(IncomingEmailKeepPendingReason::class),
            ],
        ]);

        $messageIds = array_map('intval', $validated['message_ids']);
        $actor = $request->user();
        $assigneeId = isset($validated['assignee_user_id']) ? (int) $validated['assignee_user_id'] : null;

        $result = match (IncomingEmailDisposition::from($validated['disposition'])) {
            IncomingEmailDisposition::CreateCase => $dispositions->createServiceCase(
                messageIds: $messageIds,
                actor: $actor,
                assigneeUserId: $assigneeId,
            ),
            IncomingEmailDisposition::LinkCase => $dispositions->linkExistingCase(
                messageIds: $messageIds,
                caseReference: (string) $validated['case_reference'],
                actor: $actor,
                assigneeUserId: $assigneeId,
            ),
            IncomingEmailDisposition::Ignore => $dispositions->ignore(
                messageIds: $messageIds,
                variant: IncomingEmailIgnoreDispositionVariant::from((string) $validated['ignore_variant']),
                actor: $actor,
            ),
            IncomingEmailDisposition::Spam => $dispositions->spam($messageIds, $actor),
            IncomingEmailDisposition::Promotion => $dispositions->promotion($messageIds, $actor),
            IncomingEmailDisposition::AutoProcessed => $dispositions->autoProcessed($messageIds, $actor),
            IncomingEmailDisposition::KeepPending => $dispositions->keepPending(
                messageIds: $messageIds,
                reason: IncomingEmailKeepPendingReason::from((string) $validated['keep_pending_reason']),
                actor: $actor,
            ),
        };

        $returnQueue = IncomingEmailIntakeQueue::tryFrom((string) ($validated['return_queue'] ?? ''))
            ?? IncomingEmailIntakeQueue::NeedsHuman;

        $applied = (int) ($result['applied'] ?? 0);
        $cases = $result['cases'] ?? [];
        $status = match (IncomingEmailDisposition::from($validated['disposition'])) {
            IncomingEmailDisposition::CreateCase => sprintf(
                'Created/linked %d case(s): %s',
                $applied,
                $cases === [] ? '—' : implode(', ', $cases),
            ),
            IncomingEmailDisposition::LinkCase => sprintf(
                'Linked %d email(s) to %s.',
                $applied,
                $cases === [] ? (string) $validated['case_reference'] : implode(', ', $cases),
            ),
            IncomingEmailDisposition::KeepPending => sprintf(
                'Kept %d email(s) pending disposition.',
                $applied,
            ),
            default => sprintf('Disposed %d email(s).', $applied),
        };

        return redirect()
            ->route('admin.incoming-emails.index', [
                'queue' => $returnQueue->value,
            ])
            ->with('status', $status);
    }

    private function authorizeEmailIntakeView(Request $request): void
    {
        abort_unless(IncomingEmailAccess::featureEnabled(), 404);
        abort_unless(IncomingEmailAccess::allowsView($request->user()), 403);
    }

    private function authorizeEmailIntakeManage(Request $request): void
    {
        abort_unless(IncomingEmailAccess::featureEnabled(), 404);
        abort_unless(IncomingEmailAccess::allowsManage($request->user()), 403);
    }

    private function reviewHasTeachChange(Request $request): bool
    {
        $assignee = $request->input('assignee_user_id');
        $baselineAssignee = $request->input('baseline_assignee_user_id');
        $classification = $request->input('classification');
        $baselineClassification = $request->input('baseline_classification');
        $importance = $request->input('importance');
        $baselineImportance = $request->input('baseline_importance');

        $ownerChanged = filled($assignee)
            && (string) $assignee !== (string) ($baselineAssignee ?? '');
        $classificationChanged = filled($classification)
            && (string) $classification !== (string) ($baselineClassification ?? '');
        $importanceChanged = filled($importance)
            && (string) $importance !== (string) ($baselineImportance ?? '');

        return $ownerChanged || $classificationChanged || $importanceChanged;
    }

    /**
     * @param  array{
     *     taught: bool,
     *     rules_saved: int,
     *     applied: int,
     *     cases: list<string>
     * }  $result
     */
    private function reviewStatusMessage(
        IncomingEmailDisposition $disposition,
        array $result,
        string $caseReference,
    ): string {
        $taughtPrefix = $result['taught']
            ? sprintf('Taught IRA (%d rule(s)). ', $result['rules_saved'])
            : '';

        $applied = (int) $result['applied'];
        $cases = $result['cases'];

        $dispositionMessage = match ($disposition) {
            IncomingEmailDisposition::CreateCase => sprintf(
                'Created/linked %d case(s): %s',
                $applied,
                $cases === [] ? '—' : implode(', ', $cases),
            ),
            IncomingEmailDisposition::LinkCase => sprintf(
                'Linked %d email(s) to %s.',
                $applied,
                $cases === [] ? $caseReference : implode(', ', $cases),
            ),
            IncomingEmailDisposition::KeepPending => sprintf(
                'Kept %d email(s) pending disposition.',
                $applied,
            ),
            default => sprintf('Disposed %d email(s).', $applied),
        };

        return $taughtPrefix.$dispositionMessage;
    }
}
