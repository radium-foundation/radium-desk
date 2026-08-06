<?php

namespace App\Http\Controllers;

use App\Enums\IncomingEmailImportance;
use App\Enums\IncomingEmailIgnoreLearningAction;
use App\Enums\IncomingEmailOperatorClassification;
use App\Enums\IraMemoryCreatedFrom;
use App\Enums\IraMemoryDecisionKind;
use App\Enums\IraMemoryPatternKind;
use App\Enums\IraMemorySource;
use App\Enums\IraMemoryStatus;
use App\Enums\IraMemoryType;
use App\Models\IraMemory;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\IraMemory\IraMemoryAdminPresenter;
use App\Services\IraMemory\IraMemoryQueryService;
use App\Services\IraMemory\IraMemoryService;
use App\Services\ServiceCaseAssignmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

class IraMemoryAdminController extends Controller
{
    public function index(
        Request $request,
        IraMemoryQueryService $query,
        IraMemoryAdminPresenter $presenter,
    ): View {
        $this->authorizeMemoryAdmin($request);

        $filters = $this->filtersFromRequest($request);
        $memories = $query->paginate($filters);

        return view('admin.ira-memory.index', [
            'memories' => $memories,
            'rows' => $presenter->listRows($memories->getCollection()),
            'filters' => $filters,
            'filterOptions' => $this->filterOptions(),
            'testResult' => null,
            'testInput' => [
                'from_email' => '',
                'subject' => '',
                'preview' => '',
                'mailbox' => '',
            ],
        ]);
    }

    public function show(
        Request $request,
        IraMemory $memory,
        IraMemoryAdminPresenter $presenter,
        ServiceCaseAssignmentService $assignmentService,
    ): View {
        $this->authorizeMemoryAdmin($request);

        return view('admin.ira-memory.show', [
            'memory' => $memory,
            'detail' => $presenter->detail($memory),
            'filterOptions' => $this->filterOptions(),
            'assignableUsers' => $assignmentService->reassignableAdmins(),
            'classificationOptions' => IncomingEmailOperatorClassification::teachingCases(),
            'importanceOptions' => IncomingEmailImportance::cases(),
            'ignoreActions' => IncomingEmailIgnoreLearningAction::cases(),
        ]);
    }

    public function update(
        Request $request,
        IraMemory $memory,
        IraMemoryService $service,
    ): RedirectResponse {
        $this->authorizeMemoryAdmin($request);

        abort_unless(
            in_array($memory->status, [IraMemoryStatus::Active, IraMemoryStatus::Disabled], true),
            422,
            'Only active or disabled memories can be edited.',
        );

        $validated = $request->validate([
            'pattern_kind' => ['required', Rule::enum(IraMemoryPatternKind::class)],
            'pattern_value' => ['required', 'string', 'max:255'],
            'decision_kind' => ['required', Rule::enum(IraMemoryDecisionKind::class)],
            'decision_value' => ['required', 'string', 'max:255'],
            'memory_type' => ['nullable', Rule::enum(IraMemoryType::class)],
            'reason' => ['nullable', 'string', 'max:2000'],
            'confidence' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        $decisionKind = IraMemoryDecisionKind::from($validated['decision_kind']);
        $this->assertDecisionValue($decisionKind, $validated['decision_value']);

        $memoryType = isset($validated['memory_type'])
            ? IraMemoryType::from($validated['memory_type'])
            : IraMemoryType::fromDecisionKind($decisionKind);

        $service->update($memory, [
            'pattern_kind' => IraMemoryPatternKind::from($validated['pattern_kind']),
            'pattern_value' => trim($validated['pattern_value']),
            'decision_kind' => $decisionKind,
            'decision_value' => trim($validated['decision_value']),
            'memory_type' => $memoryType,
            'reason' => filled($validated['reason'] ?? null) ? trim((string) $validated['reason']) : null,
            'confidence' => (int) $validated['confidence'],
            'created_from' => IraMemoryCreatedFrom::ManualEdit,
        ]);

        return redirect()
            ->route('admin.ira-memory.show', $memory)
            ->with('status', 'Memory updated.');
    }

    public function toggle(
        Request $request,
        IraMemory $memory,
        IraMemoryService $service,
    ): RedirectResponse {
        $this->authorizeMemoryAdmin($request);

        abort_unless(
            in_array($memory->status, [IraMemoryStatus::Active, IraMemoryStatus::Disabled], true),
            422,
            'Only active or disabled memories can be toggled.',
        );

        if ($memory->status === IraMemoryStatus::Active) {
            $service->disable($memory);
            $status = 'Memory disabled. It will no longer match incoming mail.';
        } else {
            $service->activate($memory);
            $status = 'Memory enabled. It will match incoming mail again.';
        }

        return redirect()->back()->with('status', $status);
    }

    public function merge(
        Request $request,
        IraMemoryService $service,
    ): RedirectResponse {
        $this->authorizeMemoryAdmin($request);

        $validated = $request->validate([
            'survivor_id' => ['required', 'integer', 'exists:ira_memories,id'],
            'source_ids' => ['required', 'array', 'min:1'],
            'source_ids.*' => ['integer', 'distinct', 'exists:ira_memories,id'],
        ]);

        $survivor = IraMemory::query()->findOrFail((int) $validated['survivor_id']);

        $sourceIds = collect($validated['source_ids'])
            ->map(fn ($id): int => (int) $id)
            ->reject(fn (int $id): bool => $id === $survivor->id)
            ->unique()
            ->values();

        if ($sourceIds->isEmpty()) {
            return redirect()
                ->back()
                ->withErrors(['source_ids' => 'Select at least one other memory to merge into the survivor.']);
        }

        $mergedCount = 0;

        try {
            foreach ($sourceIds as $sourceId) {
                $source = IraMemory::query()->findOrFail($sourceId);
                $service->merge($source, $survivor, $request->user());
                $mergedCount++;
            }
        } catch (InvalidArgumentException $exception) {
            return redirect()
                ->back()
                ->withErrors(['merge' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.ira-memory.show', $survivor)
            ->with('status', sprintf('Merged %d memory(ies) into survivor #%d.', $mergedCount, $survivor->id));
    }

    public function destroy(
        Request $request,
        IraMemory $memory,
        IraMemoryService $service,
    ): RedirectResponse {
        $this->authorizeMemoryAdmin($request);

        try {
            $service->softDelete($memory);
        } catch (InvalidArgumentException $exception) {
            return redirect()
                ->back()
                ->withErrors(['delete' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.ira-memory.index')
            ->with('status', 'Memory soft-deleted. Filter by Deleted to review it.');
    }

    public function test(
        Request $request,
        IraMemoryService $service,
        IraMemoryAdminPresenter $presenter,
        IraMemoryQueryService $query,
    ): View {
        $this->authorizeMemoryAdmin($request);

        $validated = $request->validate([
            'from_email' => ['nullable', 'string', 'max:255'],
            'subject' => ['nullable', 'string', 'max:500'],
            'preview' => ['nullable', 'string', 'max:2000'],
            'mailbox' => ['nullable', 'string', 'max:255'],
        ]);

        abort_unless(
            filled($validated['from_email'] ?? null)
                || filled($validated['subject'] ?? null)
                || filled($validated['preview'] ?? null)
                || filled($validated['mailbox'] ?? null),
            422,
            'Enter a sender, subject, preview, or mailbox to test.',
        );

        $matches = $service->testMatch(
            fromEmail: $validated['from_email'] ?? null,
            subject: $validated['subject'] ?? null,
            preview: $validated['preview'] ?? null,
            mailbox: $validated['mailbox'] ?? null,
        );

        $filters = $this->filtersFromRequest($request);
        $memories = $query->paginate($filters);

        return view('admin.ira-memory.index', [
            'memories' => $memories,
            'rows' => $presenter->listRows($memories->getCollection()),
            'filters' => $filters,
            'filterOptions' => $this->filterOptions(),
            'testResult' => [
                'matches' => $presenter->matchPreviewRows($matches),
                'count' => count($matches),
            ],
            'testInput' => [
                'from_email' => (string) ($validated['from_email'] ?? ''),
                'subject' => (string) ($validated['subject'] ?? ''),
                'preview' => (string) ($validated['preview'] ?? ''),
                'mailbox' => (string) ($validated['mailbox'] ?? ''),
            ],
        ]);
    }

    /**
     * @return array<string, string|null>
     */
    private function filtersFromRequest(Request $request): array
    {
        return [
            'q' => $request->input('q', $request->query('q')),
            'memory_type' => $request->input('memory_type', $request->query('memory_type')),
            'source' => $request->input('source', $request->query('source')),
            'status' => $request->input('status', $request->query('status')),
            'pattern_kind' => $request->input('pattern_kind', $request->query('pattern_kind')),
            'decision_kind' => $request->input('decision_kind', $request->query('decision_kind')),
            'confidence_band' => $request->input('confidence_band', $request->query('confidence_band')),
            'created_from' => $request->input('created_from', $request->query('created_from')),
            'has_usage' => $request->input('has_usage', $request->query('has_usage')),
            'sort' => $request->input('sort', $request->query('sort', 'updated_at')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function filterOptions(): array
    {
        return [
            'memory_types' => IraMemoryType::cases(),
            'sources' => IraMemorySource::cases(),
            'statuses' => IraMemoryStatus::cases(),
            'pattern_kinds' => array_values(array_filter(
                IraMemoryPatternKind::cases(),
                fn (IraMemoryPatternKind $kind): bool => ! in_array($kind, [
                    IraMemoryPatternKind::CustomerKey,
                    IraMemoryPatternKind::OrderPattern,
                    IraMemoryPatternKind::ChannelThread,
                ], true),
            )),
            'decision_kinds' => IraMemoryDecisionKind::cases(),
            'created_froms' => IraMemoryCreatedFrom::cases(),
            'confidence_bands' => [
                'high' => 'High (75–100)',
                'medium' => 'Medium (45–74)',
                'low' => 'Low (1–44)',
            ],
            'sorts' => [
                'updated_at' => 'Recently updated',
                'times_used' => 'Most used',
                'last_used_at' => 'Last used',
                'confidence' => 'Confidence',
                'pattern_value' => 'Pattern A–Z',
            ],
        ];
    }

    private function assertDecisionValue(IraMemoryDecisionKind $kind, string $value): void
    {
        $value = trim($value);

        match ($kind) {
            IraMemoryDecisionKind::Assign => abort_unless(
                User::query()->whereKey((int) $value)->exists(),
                422,
                'Assign decision requires a valid user id.',
            ),
            IraMemoryDecisionKind::Classification => abort_unless(
                IncomingEmailOperatorClassification::tryFrom($value) !== null,
                422,
                'Invalid classification decision value.',
            ),
            IraMemoryDecisionKind::Importance => abort_unless(
                IncomingEmailImportance::tryFrom($value) !== null,
                422,
                'Invalid importance decision value.',
            ),
            IraMemoryDecisionKind::Ignore => abort_unless(
                IncomingEmailIgnoreLearningAction::tryFrom($value) !== null,
                422,
                'Invalid ignore decision value.',
            ),
            IraMemoryDecisionKind::Disposition => null,
        };
    }

    private function authorizeMemoryAdmin(Request $request): void
    {
        abort_unless($request->user()?->can('update', SystemSetting::class), 403);
        abort_unless(config('inbound_email.enabled'), 404);
    }
}
