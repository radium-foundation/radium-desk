<?php

namespace App\Services\Dashboard;

use App\Models\User;
use App\Services\DashboardPersonalizationService;
use Illuminate\Http\Request;

/**
 * Phase 1 Operations Workspace: normalize workspace= / queue= / filter= without
 * changing queue membership, permissions, or KPI math.
 */
class OperationsWorkspaceResolver
{
    public const WORKSPACE_ACTION_REQUIRED = 'action_required';

    public const WORKSPACE_ATTENTION = 'attention';

    public const WORKSPACE_SCHEDULED = 'scheduled';

    public const WORKSPACE_WAITING_CUSTOMER = 'waiting_customer';

    public const WORKSPACE_HARDWARE = 'hardware';

    public const WORKSPACE_OVERDUE = 'overdue';

    public const WORKSPACE_MY_WORK = 'my_work';

    public const WORKSPACE_MY_ATTENTION = 'my_attention';

    /**
     * Canonical Phase 1 workspace ids (plus agent aliases accepted for soft-switch).
     *
     * @var list<string>
     */
    public const PHASE1_WORKSPACES = [
        self::WORKSPACE_ACTION_REQUIRED,
        self::WORKSPACE_ATTENTION,
        self::WORKSPACE_SCHEDULED,
        self::WORKSPACE_WAITING_CUSTOMER,
        self::WORKSPACE_HARDWARE,
        self::WORKSPACE_OVERDUE,
        self::WORKSPACE_MY_WORK,
        self::WORKSPACE_MY_ATTENTION,
    ];

    /**
     * Filter-style workspaces that must not be treated as operation queues.
     *
     * @var list<string>
     */
    private const FILTER_WORKSPACES = [
        self::WORKSPACE_OVERDUE,
        self::WORKSPACE_MY_ATTENTION,
        'warning',
        'needs_attention',
        'high_priority',
        'pending_support',
    ];

    public function __construct(
        private readonly DashboardPersonalizationService $personalization,
    ) {}

    /**
     * @return array{
     *     workspace: string,
     *     requested_queue: ?string,
     *     legacy_view: ?string,
     *     legacy_filter: ?string,
     *     operation_queue: string,
     *     service_case_filter: string,
     *     redirect: bool,
     *     live_scope: string,
     *     panel_title: string,
     * }
     */
    public function resolve(User $user, Request $request): array
    {
        [$requestedQueue, $legacyView, $legacyFilter] = $this->navigationInputsFromRequest($request);

        $queueResolution = $this->personalization->resolveQueue(
            $user,
            $requestedQueue,
            $legacyView,
            $legacyFilter,
        );
        $operationQueue = $queueResolution['queue'];
        $serviceCaseFilter = $this->personalization->resolveServiceCaseFilter(
            $user,
            $requestedQueue,
            $legacyView,
            $legacyFilter,
        );

        return [
            'workspace' => $this->canonicalWorkspace($operationQueue, $serviceCaseFilter),
            'requested_queue' => $requestedQueue,
            'legacy_view' => $legacyView,
            'legacy_filter' => $legacyFilter,
            'operation_queue' => $operationQueue,
            'service_case_filter' => $serviceCaseFilter,
            'redirect' => $queueResolution['redirect'],
            'live_scope' => $this->personalization->scopeForQueue($operationQueue, $user),
            'panel_title' => $this->panelTitle($operationQueue, $serviceCaseFilter),
        ];
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string} queue, view, filter
     */
    public function navigationInputsFromRequest(Request $request): array
    {
        $workspace = $request->query('workspace');
        $requestedQueue = $request->query('queue');
        $legacyView = $request->query('view');
        $legacyFilter = $request->query('filter');

        $requestedQueue = is_string($requestedQueue) && $requestedQueue !== '' ? $requestedQueue : null;
        $legacyView = is_string($legacyView) && $legacyView !== '' ? $legacyView : null;
        $legacyFilter = is_string($legacyFilter) && $legacyFilter !== '' ? $legacyFilter : null;

        if (is_string($workspace) && $workspace !== '') {
            return $this->workspaceToNavigationInputs($workspace, $requestedQueue, $legacyView, $legacyFilter);
        }

        return [$requestedQueue, $legacyView, $legacyFilter];
    }

    /**
     * Map a workspace id into the queue/view/filter inputs understood by
     * DashboardPersonalizationService (no behaviour change for legacy links).
     *
     * @return array{0: ?string, 1: ?string, 2: ?string}
     */
    public function workspaceToNavigationInputs(
        string $workspace,
        ?string $requestedQueue = null,
        ?string $legacyView = null,
        ?string $legacyFilter = null,
    ): array {
        $normalized = $this->personalization->normalizeRequestedQueue($workspace) ?? $workspace;

        if (in_array($normalized, self::FILTER_WORKSPACES, true)) {
            // Filter workspaces must resolve queue via legacy filter mapping so
            // admin (Ready) vs agent (My Work) behaviour stays unchanged.
            return [
                null,
                $legacyView,
                $legacyFilter ?? $normalized,
            ];
        }

        return [
            $requestedQueue ?? $normalized,
            $legacyView,
            $legacyFilter,
        ];
    }

    public function canonicalWorkspace(string $operationQueue, string $serviceCaseFilter): string
    {
        if ($serviceCaseFilter !== $operationQueue && filled($serviceCaseFilter)) {
            return $serviceCaseFilter;
        }

        return $operationQueue;
    }

    public function panelTitle(string $operationQueue, string $serviceCaseFilter): string
    {
        return match ($serviceCaseFilter) {
            'needs_attention' => 'Needs Attention',
            'my_attention' => 'My Attention',
            default => $this->personalization->serviceCasePanelTitle($operationQueue),
        };
    }

    /**
     * Query params for History API / deep links. Prefer workspace=; omit default queue.
     *
     * @return array<string, string>
     */
    public function historyQueryParams(User $user, string $operationQueue, string $serviceCaseFilter): array
    {
        $workspace = $this->canonicalWorkspace($operationQueue, $serviceCaseFilter);
        $defaultQueue = $this->personalization->defaultQueueFor($user);

        if ($serviceCaseFilter !== $operationQueue) {
            return ['workspace' => $workspace];
        }

        if ($operationQueue === $defaultQueue) {
            return ['workspace' => $workspace];
        }

        return ['workspace' => $workspace];
    }

    public function softSwitchEnabled(): bool
    {
        return (bool) config('dashboard.operations_workspace_soft_switch', true);
    }
}
