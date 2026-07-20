<?php

namespace App\Services;

use App\Events\Dashboard\DashboardKpisUpdated;
use App\Events\Dashboard\NotificationCreated;
use App\Events\Dashboard\ServiceCaseClosed;
use App\Events\Dashboard\ServiceCaseCreated;
use App\Events\Dashboard\ServiceCaseRemarked;
use App\Events\Dashboard\ServiceCaseResolved;
use App\Events\Dashboard\SlaStatusChanged;
use App\Events\Dashboard\TransactionAssigned;
use App\Models\Incident;
use App\Models\User;
use App\Services\Dashboard\DashboardLiveRowVisibilityService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;

class DashboardBroadcastService
{
    private bool $coalesceKpiRefresh = false;

    private bool $kpiRefreshPending = false;

    private ?int $coalescedKpiActorId = null;

    public function __construct(
        private readonly DashboardService $dashboardService,
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

    public function serviceCaseAssigned(Incident $incident, User $actor): void
    {
        $this->serviceCaseQueueMembershipChanged($incident, $actor);
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
        $this->runAfterDatabaseCommit(function () use ($incident, $actor): void {
            [$freshIncident, $freshActor] = $this->resolveIncidentAndActor($incident, $actor);

            if ($freshIncident === null || $freshActor === null) {
                return;
            }

            $this->broadcastRowUpdate(
                incident: $freshIncident,
                actor: $freshActor,
                eventClass: TransactionAssigned::class,
            );

            $this->kpisUpdated($freshActor);
            $this->slaStatusChanged($freshIncident, $freshActor);
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

        $this->runAfterDatabaseCommit(function () use ($incidentIds, $actor): void {
            $freshActor = User::query()->find($actor->id);

            if ($freshActor === null) {
                return;
            }

            $incidents = Incident::query()
                ->with(['order.transactionAssigner', 'creator', 'assignee'])
                ->whereIn('id', $incidentIds)
                ->get();

            foreach ($incidents as $incident) {
                $this->broadcastRowUpdate(
                    incident: $incident,
                    actor: $freshActor,
                    eventClass: TransactionAssigned::class,
                );
            }

            $this->kpisUpdated($freshActor);

            foreach ($incidents as $incident) {
                if ($incident->isPendingAdmin()) {
                    $this->broadcastRowUpdate(
                        incident: $incident,
                        actor: $freshActor,
                        eventClass: SlaStatusChanged::class,
                    );
                }
            }
        });
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
        $this->runAfterDatabaseCommit(function () use ($incident, $actor): void {
            [$freshIncident, $freshActor] = $this->resolveIncidentAndActor($incident, $actor);

            if ($freshIncident === null || $freshActor === null) {
                return;
            }

            $this->broadcastRowUpdate(
                incident: $freshIncident,
                actor: $freshActor,
                eventClass: ServiceCaseResolved::class,
            );

            $this->kpisUpdated($freshActor);
        });
    }

    public function serviceCaseClosed(Incident $incident, User $actor): void
    {
        $this->runAfterDatabaseCommit(function () use ($incident, $actor): void {
            [$freshIncident, $freshActor] = $this->resolveIncidentAndActor($incident, $actor);

            if ($freshIncident === null || $freshActor === null) {
                return;
            }

            $this->broadcastRowUpdate(
                incident: $freshIncident,
                actor: $freshActor,
                eventClass: ServiceCaseClosed::class,
            );

            $this->kpisUpdated($freshActor);
            $this->slaStatusChanged($freshIncident, $freshActor);
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
