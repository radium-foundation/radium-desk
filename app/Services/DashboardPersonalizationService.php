<?php

namespace App\Services;

use App\Models\User;
use App\Services\Operations\OperationsRoleService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DashboardPersonalizationService
{
    public const VIEW_ALL = 'all';

    public const VIEW_TEAM = 'team';

    public const VIEW_MY_WORK = 'my_work';

    public const VIEW_HARDWARE_ORDERS = 'hardware_orders';

    public const QUEUE_ACTION_REQUIRED = 'action_required';

    public const QUEUE_PENDING_REVIEW = 'pending_review';

    public const QUEUE_SCHEDULED = 'scheduled';

    public const QUEUE_WAITING_CUSTOMER = 'waiting_customer';

    public const QUEUE_ATTENTION = 'attention';

    public const QUEUE_HARDWARE = 'hardware';

    public const QUEUE_COMPLETED = 'completed';

    public const QUEUE_MY_WORK = 'my_work';

    public const PERMISSION_HARDWARE_VIEW = 'dashboard.hardware.view';

    /**
     * @var list<string>
     */
    private const HARDWARE_VIEW_ALIASES = [
        'hardware',
        'hardware_orders',
        'warehouse',
        'dispatch',
    ];

    public function __construct(
        private readonly OperationsRoleService $operationsRoles,
    ) {}

    public function defaultQueueFor(?User $user): string
    {
        if ($user === null) {
            return self::QUEUE_ACTION_REQUIRED;
        }

        if ($this->operationsRoles->isHardwareTeam($user)) {
            return self::QUEUE_HARDWARE;
        }

        if ($this->operationsRoles->usesAdminQueues($user)) {
            return self::QUEUE_ACTION_REQUIRED;
        }

        return self::QUEUE_MY_WORK;
    }

    /**
     * @return list<string>
     */
    public function availableQueuesFor(User $user): array
    {
        if ($this->operationsRoles->usesAdminQueues($user)) {
            $queues = [
                self::QUEUE_ACTION_REQUIRED,
                self::QUEUE_ATTENTION,
                self::QUEUE_SCHEDULED,
                self::QUEUE_WAITING_CUSTOMER,
            ];

            if ($this->canViewHardwareOrders($user)) {
                $queues[] = self::QUEUE_HARDWARE;
            }

            $queues[] = self::QUEUE_PENDING_REVIEW;

            return $queues;
        }

        if ($this->operationsRoles->isHardwareTeam($user)) {
            return [
                self::QUEUE_HARDWARE,
                self::QUEUE_MY_WORK,
            ];
        }

        return [
            self::QUEUE_MY_WORK,
            self::QUEUE_SCHEDULED,
            self::QUEUE_WAITING_CUSTOMER,
        ];
    }

    /**
     * @return array<string, array{label: string, icon: string, tone: string}>
     */
    public function queueMetaFor(User $user): array
    {
        $meta = config('operations.queues', []);
        $available = $this->availableQueuesFor($user);
        $filtered = [];

        foreach ($available as $queueKey) {
            if (! isset($meta[$queueKey])) {
                continue;
            }

            $filtered[$queueKey] = $meta[$queueKey];

            if ($this->operationsRoles->usesSupportQueues($user)) {
                $filtered[$queueKey] = match ($queueKey) {
                    self::QUEUE_MY_WORK => [
                        'label' => 'Active',
                        'tone' => 'neutral',
                    ],
                    self::QUEUE_SCHEDULED => [
                        'label' => 'Appointments',
                        'tone' => 'neutral',
                    ],
                    self::QUEUE_WAITING_CUSTOMER => [
                        'label' => 'Waiting',
                        'tone' => 'neutral',
                    ],
                    default => $meta[$queueKey],
                };
            }
        }

        return $filtered;
    }

    public function showsQueueNavigation(User $user): bool
    {
        return $this->availableQueuesFor($user) !== [];
    }

    public function hidesZeroCountQueueTabs(User $user): bool
    {
        return $this->operationsRoles->usesSupportQueues($user);
    }

    /**
     * @return array{queue: string, redirect: bool}
     */
    public function resolveQueue(User $user, ?string $requestedQueue, ?string $legacyView = null, ?string $legacyFilter = null): array
    {
        $defaultQueue = $this->defaultQueueFor($user);
        $availableQueues = $this->availableQueuesFor($user);
        $normalized = $this->normalizeRequestedQueue($requestedQueue);

        if ($normalized === null && ($legacyView !== null || $legacyFilter !== null)) {
            $mapped = $this->mapLegacyNavigation($user, $legacyView, $legacyFilter);

            if ($mapped !== null) {
                if ($mapped === self::QUEUE_HARDWARE && ! $this->canViewHardwareOrders($user)) {
                    return ['queue' => $defaultQueue, 'redirect' => true];
                }

                if ($mapped === self::QUEUE_COMPLETED) {
                    return ['queue' => $defaultQueue, 'redirect' => true];
                }

                $needsRedirect = $requestedQueue === null
                    && $legacyView !== null
                    && ! filled($legacyFilter);

                return ['queue' => $mapped, 'redirect' => $needsRedirect];
            }
        }

        if ($normalized === self::QUEUE_HARDWARE && ! $this->canViewHardwareOrders($user)) {
            return ['queue' => $defaultQueue, 'redirect' => true];
        }

        if ($normalized === null) {
            return ['queue' => $defaultQueue, 'redirect' => false];
        }

        if (! in_array($normalized, $availableQueues, true)) {
            return ['queue' => $defaultQueue, 'redirect' => true];
        }

        return ['queue' => $normalized, 'redirect' => false];
    }

    public function resolveServiceCaseFilter(
        User $user,
        ?string $requestedQueue,
        ?string $legacyView = null,
        ?string $legacyFilter = null,
    ): string {
        if (filled($legacyFilter)) {
            if ($legacyFilter === 'completed') {
                return $this->legacyFilterForQueue($this->defaultQueueFor($user));
            }

            return $legacyFilter;
        }

        $normalized = $this->normalizeRequestedQueue($requestedQueue);

        if ($normalized !== null) {
            return $normalized;
        }

        return $this->defaultQueueFor($user);
    }

    public function normalizeRequestedQueue(?string $requestedQueue): ?string
    {
        if ($requestedQueue === null || $requestedQueue === '') {
            return null;
        }

        if (in_array($requestedQueue, self::HARDWARE_VIEW_ALIASES, true)) {
            return self::QUEUE_HARDWARE;
        }

        return $requestedQueue;
    }

    public function redirectToResolvedQueue(Request $request, User $user, string $queue): ?RedirectResponse
    {
        $params = [];

        if ($queue !== $this->defaultQueueFor($user)) {
            $params['queue'] = $queue;
        }

        $preserveQuery = filled($request->query('q'))
            ? ['q' => $request->query('q')]
            : [];

        $currentParams = array_filter([
            'queue' => $request->query('queue'),
            'view' => $request->query('view'),
            'filter' => $request->query('filter'),
        ], fn (mixed $value): bool => filled($value));

        if ($params === [] && $currentParams === []) {
            return null;
        }

        if ($params === [] && $currentParams !== []) {
            return redirect()->route('dashboard', $preserveQuery);
        }

        if (($currentParams['queue'] ?? null) === ($params['queue'] ?? null) && ! isset($currentParams['view'], $currentParams['filter'])) {
            return null;
        }

        return redirect()->route('dashboard', array_merge($params, $preserveQuery));
    }

    public function resolveAssignedToScope(User $user, string $queue): ?User
    {
        if ($queue === self::QUEUE_MY_WORK) {
            return $user;
        }

        if ($queue === self::QUEUE_WAITING_CUSTOMER && $this->operationsRoles->usesSupportQueues($user)) {
            return $user;
        }

        if ($queue === self::QUEUE_COMPLETED && $this->operationsRoles->usesSupportQueues($user)) {
            return $user;
        }

        return null;
    }

    public function prioritizesRecentAssignments(string $queue): bool
    {
        return $queue === self::QUEUE_MY_WORK;
    }

    public function serviceCasePanelTitle(string $queue): string
    {
        return config("operations.queues.{$queue}.panel_title")
            ?? config("operations.queues.{$queue}.label", 'Service Cases');
    }

    public function showsServiceCasesPanel(string $queue): bool
    {
        $user = auth()->user();

        return $queue !== self::QUEUE_HARDWARE
            || $user === null
            || ! $this->operationsRoles->isHardwareTeam($user);
    }

    public function canViewHardwareOrders(User $user): bool
    {
        return $user->can(self::PERMISSION_HARDWARE_VIEW);
    }

    public function defaultViewFor(?User $user): string
    {
        if ($user === null) {
            return self::VIEW_ALL;
        }

        if ($user->hasRole(RolePermissionSeeder::ROLE_SUPERADMIN)) {
            return self::VIEW_ALL;
        }

        if ($user->hasAnyRole(RolePermissionSeeder::ADMIN_TEAM_ROLES)) {
            return self::VIEW_TEAM;
        }

        return self::VIEW_MY_WORK;
    }

    public function defaultFilterFor(User $user, string $view): string
    {
        return $this->legacyFilterForQueue($this->defaultQueueFor($user));
    }

    /**
     * @return list<string>
     */
    public function availableFiltersFor(User $user): array
    {
        return $this->availableQueuesFor($user);
    }

    /**
     * @return array<string, array{label: string, icon: string}>
     */
    public function availableModulesFor(User $user): array
    {
        return [];
    }

    public function showsModuleNavigation(User $user): bool
    {
        return false;
    }

    public function normalizeRequestedView(?string $requestedView): ?string
    {
        if ($requestedView === null || $requestedView === '') {
            return null;
        }

        if (in_array($requestedView, self::HARDWARE_VIEW_ALIASES, true)) {
            return self::VIEW_HARDWARE_ORDERS;
        }

        return $requestedView;
    }

    /**
     * @return array{view: string, redirect: bool}
     */
    public function resolveView(User $user, ?string $requestedView): array
    {
        $queueResolution = $this->resolveQueue($user, null, $requestedView, null);

        return [
            'view' => $this->legacyViewForQueue($queueResolution['queue']),
            'redirect' => $queueResolution['redirect'],
        ];
    }

    public function redirectToResolvedView(Request $request, User $user, string $view, string $filter): ?RedirectResponse
    {
        $queue = $this->mapLegacyNavigation($user, $view, $filter) ?? $this->defaultQueueFor($user);

        return $this->redirectToResolvedQueue($request, $user, $queue);
    }

    public function serviceCasePanelTitleForView(string $view): string
    {
        return match ($view) {
            self::VIEW_MY_WORK => 'My Work',
            self::VIEW_TEAM => 'Team Service Cases',
            self::VIEW_ALL => 'All Service Cases',
            default => 'Recent Service Cases',
        };
    }

    public function scopesServiceCasesToAssignee(string $view): bool
    {
        return $view === self::VIEW_MY_WORK;
    }

    public function prioritizesRecentAssignmentsForView(string $view): bool
    {
        return $view === self::VIEW_MY_WORK;
    }

    public function showsServiceCasesPanelForView(string $view): bool
    {
        return $view !== self::VIEW_HARDWARE_ORDERS;
    }

    public function showsHardwareOrdersPanel(string $view): bool
    {
        return $view === self::VIEW_HARDWARE_ORDERS;
    }

    private function mapLegacyNavigation(User $user, ?string $legacyView, ?string $legacyFilter): ?string
    {
        $view = $this->normalizeRequestedView($legacyView);
        $filter = filled($legacyFilter) ? $legacyFilter : null;

        if ($view === self::VIEW_HARDWARE_ORDERS || in_array((string) $legacyView, self::HARDWARE_VIEW_ALIASES, true)) {
            return self::QUEUE_HARDWARE;
        }

        if ($filter === 'completed') {
            return self::QUEUE_COMPLETED;
        }

        if (in_array($filter, ['overdue', 'warning'], true)) {
            return $this->operationsRoles->usesSupportQueues($user)
                ? self::QUEUE_MY_WORK
                : self::QUEUE_ACTION_REQUIRED;
        }

        if (in_array($filter, ['needs_attention', 'my_attention', 'high_priority', 'pending_support'], true)) {
            return $this->operationsRoles->usesSupportQueues($user)
                ? self::QUEUE_MY_WORK
                : self::QUEUE_ATTENTION;
        }

        if ($view === self::VIEW_MY_WORK || $filter === 'my_cases') {
            return self::QUEUE_MY_WORK;
        }

        if ($filter === 'pending_admin' || $filter === 'all' || $view === self::VIEW_ALL || $view === self::VIEW_TEAM) {
            return $this->operationsRoles->usesAdminQueues($user)
                ? self::QUEUE_ACTION_REQUIRED
                : self::QUEUE_MY_WORK;
        }

        return null;
    }

    private function legacyFilterForQueue(string $queue): string
    {
        return match ($queue) {
            self::QUEUE_COMPLETED => 'completed',
            self::QUEUE_ATTENTION => 'needs_attention',
            self::QUEUE_MY_WORK => 'my_cases',
            self::QUEUE_HARDWARE => 'all',
            self::QUEUE_ACTION_REQUIRED,
            self::QUEUE_PENDING_REVIEW,
            self::QUEUE_SCHEDULED,
            self::QUEUE_WAITING_CUSTOMER => $queue,
            default => self::QUEUE_ACTION_REQUIRED,
        };
    }

    private function legacyViewForQueue(string $queue): string
    {
        return match ($queue) {
            self::QUEUE_HARDWARE => self::VIEW_HARDWARE_ORDERS,
            self::QUEUE_MY_WORK => self::VIEW_MY_WORK,
            default => self::VIEW_ALL,
        };
    }
}
