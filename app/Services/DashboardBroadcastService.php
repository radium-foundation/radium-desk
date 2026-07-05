<?php

namespace App\Services;

use App\Enums\IncidentStatus;
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
            removeFromList: ! $incident->isPendingAdmin(),
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
                removeFromList: ! $incident->isPendingAdmin(),
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
            removeFromList: $this->shouldRemoveFromDefaultList($incident),
        );

        $this->kpisUpdated($actor);
    }

    public function serviceCaseClosed(Incident $incident, User $actor): void
    {
        $this->broadcastRowUpdate(
            incident: $incident,
            actor: $actor,
            eventClass: ServiceCaseClosed::class,
            removeFromList: true,
        );

        $this->kpisUpdated($actor);
        $this->slaStatusChanged($incident, $actor);
    }

    public function kpisUpdated(?User $actor = null): void
    {
        foreach ($this->recipientsExcept($actor) as $recipient) {
            broadcast(new DashboardKpisUpdated(
                recipient: $recipient,
                kpiStripHtml: $this->dashboardService->renderKpiStrip(
                    $this->dashboardService->statsFor($recipient),
                    $recipient,
                ),
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

    public function notificationCreated(User $recipient, DatabaseNotification $notification): void
    {
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
        ));
    }

    /**
     * @param  class-string<DashboardBroadcastEvent>  $eventClass
     */
    private function broadcastRowUpdate(
        Incident $incident,
        ?User $actor,
        string $eventClass,
        bool $removeFromList = false,
    ): void {
        $incident = $this->loadIncidentRelations($incident);

        foreach ($this->recipientsExcept($actor) as $recipient) {
            if (! $recipient->can('view', $incident)) {
                continue;
            }

            broadcast(new $eventClass(
                recipient: $recipient,
                incident: $incident,
                rowHtml: $this->renderRow($incident, $recipient),
                removeFromList: $removeFromList,
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
        return $incident->loadMissing(['order.transactionAssigner', 'creator', 'assignee']);
    }

    private function renderRow(Incident $incident, User $recipient): string
    {
        return view(
            'dashboard.partials.service-case-row',
            $this->dashboardService->serviceCaseRowViewData($incident, $recipient),
        )->render();
    }

    private function shouldRemoveFromDefaultList(Incident $incident): bool
    {
        if (in_array($incident->status, [IncidentStatus::Resolved, IncidentStatus::Closed], true)) {
            return true;
        }

        return ! $incident->isPendingAdmin();
    }
}
