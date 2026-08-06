<?php

namespace App\Http\Controllers;

use App\Enums\IncomingEmailDisposition;
use App\Enums\IncomingEmailIgnoreDispositionVariant;
use App\Enums\IncomingEmailIgnoreLearningAction;
use App\Enums\IncomingEmailImportance;
use App\Enums\IncomingEmailIntakeQueue;
use App\Enums\IncomingEmailKeepPendingReason;
use App\Enums\IncomingEmailLearningScope;
use App\Enums\IncomingEmailOperatorClassification;
use App\Models\SystemSetting;
use App\Services\IncomingEmail\IncomingEmailDispositionService;
use App\Services\IncomingEmail\IncomingEmailIntakeCounterService;
use App\Services\IncomingEmail\IncomingEmailLearningActionService;
use App\Services\IncomingEmail\IncomingEmailLearningCenterPresenter;
use App\Services\ServiceCaseAssignmentService;
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
        $this->authorizeEmailAdmin($request);

        $queue = IncomingEmailIntakeQueue::tryFrom((string) $request->query('queue', ''))
            ?? IncomingEmailIntakeQueue::NeedsHuman;

        $messages = $counters
            ->queryForQueue($queue)
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
            'messages' => $messages,
            'cards' => $presenter->cardsFor($messages, $queue),
            'counts' => $counts,
            'queues' => IncomingEmailIntakeQueue::cases(),
            'isLearningCenter' => true,
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
        $this->authorizeEmailAdmin($request);

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

    public function applyDisposition(
        Request $request,
        IncomingEmailDispositionService $dispositions,
    ): RedirectResponse {
        $this->authorizeEmailAdmin($request);

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

    private function authorizeEmailAdmin(Request $request): void
    {
        abort_unless($request->user()?->can('update', SystemSetting::class), 403);
        abort_unless(config('inbound_email.enabled'), 404);
    }
}
