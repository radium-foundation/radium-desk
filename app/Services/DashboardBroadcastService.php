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

class DashboardBroadcastService
{
    public function __construct(
        private readonly DashboardService $dashboardService,
    ) {}

    public function serviceCaseCreated(Incident $incident, User $actor): void
    {
        $this->broadcastRowUpdate(
            incident: $incident,
            actor: $actor,
            eventClass: ServiceCaseCreated::class,
        );

        $this->kpisUpdated($actor);
        $this->slaStatusChanged($incident, $actor);
    }

    public function serviceCaseAssigned(Incident $incident, User $actor): void
    {
        $this->serviceCaseQueueMembershipChanged($incident, $actor);
    }

    public function serviceCaseQueueMembershipChanged(Incident $incident, ?User $actor = null): void
    {
        $this->broadcastRowUpdate(
            incident: $incident,
            actor: $actor,
            eventClass: ServiceCaseCreated::class,
        );

        $this->kpisUpdated($actor);
    }

    public function transactionAssigned(Incident $incident, User $actor): void
    {
        $this->broadcastRowUpdate(
            incident: $incident,
            actor: $actor,
            eventClass: TransactionAssigned::class,
        );

        $this->kpisUpdated($actor);
        $this->slaStatusChanged($incident, $actor);
    }

    /**
     * @param  list<int>  $incidentIds
     */
    public function transactionsAssigned(array $incidentIds, User $actor): void
    {
        if ($incidentIds === []) {
            return;
        }

        $incidents = Incident::query()
            ->with(['order.transactionAssigner', 'creator', 'assignee'])
            ->whereIn('id', $incidentIds)
            ->get();

        foreach ($incidents as $incident) {
            $this->broadcastRowUpdate(
                incident: $incident,
                actor: $actor,
                eventClass: TransactionAssigned::class,
            );
        }

        $this->kpisUpdated($actor);

        foreach ($incidents as $incident) {
            if ($incident->isPendingAdmin()) {
                $this->broadcastRowUpdate(
                    incident: $incident,
                    actor: $actor,
                    eventClass: SlaStatusChanged::class,
                );
            }
        }
    }

    public function serviceCaseRemarked(Incident $incident, User $actor): void
    {
        $this->broadcastRowUpdate(
            incident: $incident,
            actor: $actor,
            eventClass: ServiceCaseRemarked::class,
        );
    }

    public function serviceCaseResolved(Incident $incident, User $actor): void
    {
        $this->broadcastRowUpdate(
            incident: $incident,
            actor: $actor,
            eventClass: ServiceCaseResolved::class,
        );

        $this->kpisUpdated($actor);
    }

    public function serviceCaseClosed(Incident $incident, User $actor): void
    {
        $this->broadcastRowUpdate(
            incident: $incident,
            actor: $actor,
            eventClass: ServiceCaseClosed::class,
        );

        $this->kpisUpdated($actor);
        $this->slaStatusChanged($incident, $actor);
    }

    public function kpisUpdated(?User $actor = null): void
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

    public function slaStatusChanged(Incident $incident, ?User $actor = null): void
    {
        if (! $incident->isPendingAdmin()) {
            return;
        }

        $this->broadcastRowUpdate(
            incident: $incident,
            actor: $actor,
            eventClass: SlaStatusChanged::class,
        );

        $this->kpisUpdated($actor);
    }

    public function notificationCreated(
        User $recipient,
        DatabaseNotification $notification,
        bool $suppressDesktopNotification = false,
    ): void {
        $unreadCount = $recipient->unreadNotifications()->count();
        $badge = match (true) {
            $unreadCount <= 0 => null,
            $unreadCount > 99 => '99+',
            default => (string) $unreadCount,
        };

        $latestNotifications = $recipient->notifications()->latest()->limit(10)->get();

        broadcast(new NotificationCreated(
            recipient: $recipient,
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
        broadcast(new NotificationCreated(
            recipient: $recipient,
            notificationId: 'incoming-call-'.$interaction['call_id'].'-'.$interaction['status'],
            title: '',
            message: '',
            url: '',
            unreadCount: $recipient->unreadNotifications()->count(),
            bellHtml: '',
            interaction: $interaction,
        ));
    }

    /**
     * @param  class-string<DashboardBroadcastEvent>  $eventClass
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
}
