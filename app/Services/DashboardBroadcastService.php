<?php

namespace App\Services;

use App\Events\Dashboard\DashboardKpisUpdated;
use App\Events\Dashboard\HybridIncidentsUpdated;
use App\Events\Dashboard\NotificationCreated;
use App\Events\Dashboard\ReferenceNumbersUpdated;
use App\Events\Dashboard\ServiceCaseCreated;
use App\Events\Dashboard\ServiceCaseRemarked;
use App\Events\Dashboard\ServiceCasesAssigned;
use App\Events\Dashboard\ServiceCasesClosed;
use App\Events\Dashboard\ServiceCasesResolved;
use App\Events\Dashboard\SlaStatusChanged;
use App\Models\Incident;
use App\Models\User;
use App\Services\Dashboard\DashboardLiveRowVisibilityService;
use App\Services\HybridRealtime\HybridRealtimeFeature;
use App\Services\HybridRealtime\HybridRealtimeFeatureService;
use App\Services\Operations\OperationsQueueClassifier;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;

class DashboardBroadcastService
{
    private bool $coalesceKpiRefresh = false;

    private bool $kpiRefreshPending = false;

    private ?int $coalescedKpiActorId = null;

    private bool $coalesceAssignmentRefresh = false;

    /** @var list<int> */
    private array $coalescedAssignmentIncidentIds = [];

    private ?int $coalescedAssignmentActorId = null;

    public function __construct(
        private readonly DashboardService $dashboardService,
        private readonly HybridRealtimeFeatureService $hybridRealtime,
    ) {}

    /**
     * Coalesce repeated kpisUpdated() calls into a single refresh (flushed explicitly).
     */
    public function beginKpiCoalesce(?User $actor = null): void
    {
        $this->coalesceKpiRefresh = true;
        $this->kpiRefreshPending = false;
        $this->coalescedKpiActorId = $actor?->id;
    }

    public function flushKpiCoalesce(): void
    {
        $actorId = $this->coalescedKpiActorId;
        $pending = $this->kpiRefreshPending;

        $this->coalesceKpiRefresh = false;
        $this->kpiRefreshPending = false;
        $this->coalescedKpiActorId = null;

        if (! $pending) {
            return;
        }

        $actor = $actorId !== null ? User::query()->find($actorId) : null;
        $this->dispatchKpisUpdated($actor);
    }

    public function cancelKpiCoalesce(): void
    {
        $this->coalesceKpiRefresh = false;
        $this->kpiRefreshPending = false;
        $this->coalescedKpiActorId = null;
    }

    public function serviceCaseCreated(Incident $incident, User $actor): void
    {
        $this->runAfterDatabaseCommit(function () use ($incident, $actor): void {
            [$freshIncident, $freshActor] = $this->resolveIncidentAndActor($incident, $actor);

            if ($freshIncident === null || $freshActor === null) {
                return;
            }

            $this->broadcastRowUpdate(
                incident: $freshIncident,
                actor: $freshActor,
                eventClass: ServiceCaseCreated::class,
            );

            $this->kpisUpdated($freshActor);
            $this->slaStatusChanged($freshIncident, $freshActor);
        });
    }

    /**
     * Coalesce repeated assignment broadcasts into one lightweight event (flushed explicitly).
     */
    public function beginAssignmentCoalesce(?User $actor = null): void
    {
        $this->coalesceAssignmentRefresh = true;
        $this->coalescedAssignmentIncidentIds = [];
        $this->coalescedAssignmentActorId = $actor?->id;
    }

    public function flushAssignmentCoalesce(): void
    {
        $actorId = $this->coalescedAssignmentActorId;
        $incidentIds = $this->coalescedAssignmentIncidentIds;

        $this->coalesceAssignmentRefresh = false;
        $this->coalescedAssignmentIncidentIds = [];
        $this->coalescedAssignmentActorId = null;

        if ($incidentIds === [] || $actorId === null) {
            return;
        }

        $actor = User::query()->find($actorId);

        if ($actor === null) {
            return;
        }

        $this->serviceCasesAssigned($incidentIds, $actor);
    }

    public function cancelAssignmentCoalesce(): void
    {
        $this->coalesceAssignmentRefresh = false;
        $this->coalescedAssignmentIncidentIds = [];
        $this->coalescedAssignmentActorId = null;
    }

    public function serviceCaseAssigned(Incident $incident, User $actor): void
    {
        if (! $this->hybridRealtime->enabled(HybridRealtimeFeature::ASSIGNMENT)) {
            return;
        }

        if ($this->coalesceAssignmentRefresh) {
            $this->coalescedAssignmentIncidentIds[] = (int) $incident->id;
            $this->coalescedAssignmentActorId = $actor->id;

            return;
        }

        $this->serviceCasesAssigned([(int) $incident->id], $actor);
    }

    /**
     * @param  list<int>  $incidentIds
     */
    public function serviceCasesAssigned(array $incidentIds, User $actor): void
    {
        if (! $this->hybridRealtime->enabled(HybridRealtimeFeature::ASSIGNMENT)) {
            return;
        }

        $this->broadcastHybridIncidentUpdates(
            incidentIds: $incidentIds,
            actor: $actor,
            eventClass: ServiceCasesAssigned::class,
        );
    }

    public function serviceCaseQueueMembershipChanged(Incident $incident, ?User $actor = null): void
    {
        $this->runAfterDatabaseCommit(function () use ($incident, $actor): void {
            [$freshIncident, $freshActor] = $this->resolveIncidentAndActor($incident, $actor);

            if ($freshIncident === null) {
                return;
            }

            $this->broadcastRowUpdate(
                incident: $freshIncident,
                actor: $freshActor,
                eventClass: ServiceCaseCreated::class,
            );

            $this->kpisUpdated($freshActor);
        });
    }

    public function transactionAssigned(Incident $incident, User $actor): void
    {
        if (! $this->hybridRealtime->enabled(HybridRealtimeFeature::REFERENCE_NUMBER)) {
            return;
        }

        $this->runAfterDatabaseCommit(function () use ($incident, $actor): void {
            [$freshIncident, $freshActor] = $this->resolveIncidentAndActor($incident, $actor);

            if ($freshIncident === null || $freshActor === null) {
                return;
            }

            $this->broadcastReferenceNumbersUpdated([$freshIncident->id], $freshActor);
        });
    }

    /**
     * @param  list<int>  $incidentIds
     */
    public function transactionsAssigned(array $incidentIds, User $actor): void
    {
        if ($incidentIds === []) {
            return;
        }

        if (! $this->hybridRealtime->enabled(HybridRealtimeFeature::REFERENCE_NUMBER)) {
            return;
        }

        $this->runAfterDatabaseCommit(function () use ($incidentIds, $actor): void {
            $freshActor = User::query()->find($actor->id);

            if ($freshActor === null) {
                return;
            }

            $this->broadcastReferenceNumbersUpdated(
                array_values(array_unique(array_map('intval', $incidentIds))),
                $freshActor,
            );
        });
    }

    /**
     * Lightweight Hybrid Realtime fan-out for Reference Number updates.
     * One event per recipient; no HTML payload and no KPI refresh.
     *
     * @param  list<int>  $incidentIds
     */
    private function broadcastReferenceNumbersUpdated(array $incidentIds, User $actor): void
    {
        if ($incidentIds === []) {
            return;
        }

        $this->dashboardService->forgetSnapshot();

        $incidents = Incident::query()
            ->whereIn('id', $incidentIds)
            ->get()
            ->keyBy('id');

        $updatedAt = now()->toIso8601String();

        foreach ($this->recipientsExcept($actor) as $recipient) {
            $visibleIds = [];

            foreach ($incidentIds as $incidentId) {
                $incident = $incidents->get($incidentId);

                if ($incident === null || ! $recipient->can('view', $incident)) {
                    continue;
                }

                $visibleIds[] = (int) $incidentId;
            }

            if ($visibleIds === []) {
                continue;
            }

            broadcast(new ReferenceNumbersUpdated(
                recipient: $recipient,
                incidentIds: $visibleIds,
                updatedAt: $updatedAt,
            ));
        }
    }

    public function serviceCaseRemarked(Incident $incident, User $actor): void
    {
        $this->runAfterDatabaseCommit(function () use ($incident, $actor): void {
            [$freshIncident, $freshActor] = $this->resolveIncidentAndActor($incident, $actor);

            if ($freshIncident === null || $freshActor === null) {
                return;
            }

            $this->broadcastRowUpdate(
                incident: $freshIncident,
                actor: $freshActor,
                eventClass: ServiceCaseRemarked::class,
            );
        });
    }

    public function serviceCaseResolved(Incident $incident, User $actor): void
    {
        if (! $this->hybridRealtime->enabled(HybridRealtimeFeature::CLOSE_RESOLVE)) {
            return;
        }

        $this->serviceCasesResolved([(int) $incident->id], $actor);
    }

    /**
     * @param  list<int>  $incidentIds
     */
    public function serviceCasesResolved(array $incidentIds, User $actor): void
    {
        if (! $this->hybridRealtime->enabled(HybridRealtimeFeature::CLOSE_RESOLVE)) {
            return;
        }

        $this->broadcastHybridIncidentUpdates(
            incidentIds: $incidentIds,
            actor: $actor,
            eventClass: ServiceCasesResolved::class,
        );
    }

    public function serviceCaseClosed(Incident $incident, User $actor): void
    {
        if (! $this->hybridRealtime->enabled(HybridRealtimeFeature::CLOSE_RESOLVE)) {
            return;
        }

        $this->serviceCasesClosed([(int) $incident->id], $actor);
    }

    /**
     * @param  list<int>  $incidentIds
     */
    public function serviceCasesClosed(array $incidentIds, User $actor): void
    {
        if (! $this->hybridRealtime->enabled(HybridRealtimeFeature::CLOSE_RESOLVE)) {
            return;
        }

        $this->broadcastHybridIncidentUpdates(
            incidentIds: $incidentIds,
            actor: $actor,
            eventClass: ServiceCasesClosed::class,
        );
    }

    /**
     * Lightweight Hybrid Realtime fan-out for assignment / resolve / close.
     * One event per recipient; no HTML payload and no KPI refresh.
     *
     * @param  list<int>  $incidentIds
     * @param  class-string<HybridIncidentsUpdated>  $eventClass
     */
    private function broadcastHybridIncidentUpdates(
        array $incidentIds,
        User $actor,
        string $eventClass,
    ): void {
        $incidentIds = array_values(array_unique(array_map('intval', $incidentIds)));

        if ($incidentIds === []) {
            return;
        }

        $this->runAfterDatabaseCommit(function () use ($incidentIds, $actor, $eventClass): void {
            $freshActor = User::query()->find($actor->id);

            if ($freshActor === null) {
                return;
            }

            $this->dashboardService->forgetSnapshot();

            $incidents = Incident::query()
                ->whereIn('id', $incidentIds)
                ->get()
                ->keyBy('id');

            $updatedAt = now()->toIso8601String();

            foreach ($this->recipientsExcept($freshActor) as $recipient) {
                $payloadIncidents = [];

                foreach ($incidentIds as $incidentId) {
                    $incident = $incidents->get($incidentId);

                    if ($incident === null || ! $recipient->can('view', $incident)) {
                        continue;
                    }

                    $payloadIncidents[] = [
                        'incident_id' => (int) $incidentId,
                        // Resolve lazily to avoid a constructor DI cycle through assignment services.
                        'queue' => app(OperationsQueueClassifier::class)->classify($incident)->value,
                        'status' => $incident->status->value,
                        'updated_at' => $incident->updated_at?->toIso8601String() ?? $updatedAt,
                    ];
                }

                if ($payloadIncidents === []) {
                    continue;
                }

                broadcast(new $eventClass(
                    recipient: $recipient,
                    incidents: $payloadIncidents,
                ));
            }
        });
    }

    public function kpisUpdated(?User $actor = null): void
    {
        if ($this->coalesceKpiRefresh) {
            $this->kpiRefreshPending = true;

            if ($actor !== null) {
                $this->coalescedKpiActorId = $actor->id;
            }

            return;
        }

        $this->runAfterDatabaseCommit(function () use ($actor): void {
            $freshActor = $actor !== null ? User::query()->find($actor->id) : null;
            $this->dispatchKpisUpdated($freshActor);
        });
    }

    public function slaStatusChanged(Incident $incident, ?User $actor = null): void
    {
        if (! $incident->isPendingAdmin()) {
            return;
        }

        $this->runAfterDatabaseCommit(function () use ($incident, $actor): void {
            [$freshIncident, $freshActor] = $this->resolveIncidentAndActor($incident, $actor);

            if ($freshIncident === null || ! $freshIncident->isPendingAdmin()) {
                return;
            }

            $this->broadcastRowUpdate(
                incident: $freshIncident,
                actor: $freshActor,
                eventClass: SlaStatusChanged::class,
            );

            $this->kpisUpdated($freshActor);
        });
    }

    public function notificationCreated(
        User $recipient,
        DatabaseNotification $notification,
        bool $suppressDesktopNotification = false,
    ): void {
        $this->runAfterDatabaseCommit(function () use ($recipient, $notification, $suppressDesktopNotification): void {
            $freshRecipient = User::query()->find($recipient->id);

            if ($freshRecipient === null) {
                return;
            }

            $unreadCount = $freshRecipient->unreadNotifications()->count();
            $badge = match (true) {
                $unreadCount <= 0 => null,
                $unreadCount > 99 => '99+',
                default => (string) $unreadCount,
            };

            $latestNotifications = $freshRecipient->notifications()->latest()->limit(10)->get();

            broadcast(new NotificationCreated(
                recipient: $freshRecipient,
                notificationId: (string) $notification->id,
                title: $notification->data['title'] ?? 'Notification',
                message: $notification->data['message'] ?? '',
                url: $notification->data['url'] ?? route('notifications.index'),
                unreadCount: $unreadCount,
                bellHtml: view('layouts.partials.notification-bell', [
                    'notificationUnreadCount' => $unreadCount,
                    'notificationUnreadBadge' => $badge,
                    'latestNotifications' => $latestNotifications,
                ])->render(),
                interaction: $notification->data['interaction'] ?? null,
                suppressDesktopNotification: $suppressDesktopNotification,
            ));
        });
    }

    /**
     * @param  array{
     *     channel: string,
     *     status: string,
     *     call_id: string,
     *     incident_id: ?int,
     *     customer_phone: ?string,
     *     customer_name: ?string,
     *     direction: string,
     *     reference_label: string,
     * }  $interaction
     */
    public function incomingCallInteraction(User $recipient, array $interaction): void
    {
        $this->runAfterDatabaseCommit(function () use ($recipient, $interaction): void {
            $freshRecipient = User::query()->find($recipient->id);

            if ($freshRecipient === null) {
                return;
            }

            broadcast(new NotificationCreated(
                recipient: $freshRecipient,
                notificationId: 'incoming-call-'.$interaction['call_id'].'-'.$interaction['status'],
                title: '',
                message: '',
                url: '',
                unreadCount: $freshRecipient->unreadNotifications()->count(),
                bellHtml: '',
                interaction: $interaction,
            ));
        });
    }

    private function dispatchKpisUpdated(?User $actor): void
    {
        $this->dashboardService->forgetSnapshot();

        foreach ($this->recipientsExcept($actor) as $recipient) {
            $metrics = $this->dashboardService->liveReverbMetricsFor($recipient);

            broadcast(new DashboardKpisUpdated(
                recipient: $recipient,
                kpiStripHtml: $metrics['kpi_strip_html'],
                serviceCaseFilterCountVariants: $metrics['service_case_filter_count_variants'],
            ));
        }
    }

    /**
     * @param  class-string<\App\Events\Dashboard\DashboardBroadcastEvent>  $eventClass
     */
    private function broadcastRowUpdate(
        Incident $incident,
        ?User $actor,
        string $eventClass,
    ): void {
        $incident = $this->loadIncidentRelations($incident);
        $this->dashboardService->forgetSnapshot();

        foreach ($this->recipientsExcept($actor) as $recipient) {
            if (! $recipient->can('view', $incident)) {
                continue;
            }

            $payload = app(DashboardLiveRowVisibilityService::class)->rowBroadcastPayload($incident, $recipient);

            broadcast(new $eventClass(
                recipient: $recipient,
                incident: $incident,
                incidentQueue: $payload['queue'],
                listActions: $payload['list_actions'],
                rowHtml: $payload['html'],
            ));
        }
    }

    /**
     * @return Collection<int, User>
     */
    private function recipientsExcept(?User $actor): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->when($actor !== null, fn ($query) => $query->whereKeyNot($actor->id))
            ->get()
            ->filter(fn (User $user): bool => $user->can('incidents.view'));
    }

    private function loadIncidentRelations(Incident $incident): Incident
    {
        return $incident->loadMissing([
            'order.transactionAssigner',
            'creator',
            'assignee.roles',
            'activeWaitingState',
            'activeBusinessHold',
            'supportAppointments',
        ]);
    }

    /**
     * @return array{0: ?Incident, 1: ?User}
     */
    private function resolveIncidentAndActor(Incident $incident, ?User $actor): array
    {
        $freshIncident = Incident::query()->find($incident->id);
        $freshActor = $actor !== null ? User::query()->find($actor->id) : null;

        return [$freshIncident, $freshActor];
    }

    private function runAfterDatabaseCommit(callable $callback): void
    {
        if (DB::transactionLevel() > 0) {
            DB::afterCommit($callback);

            return;
        }

        $callback();
    }
}
