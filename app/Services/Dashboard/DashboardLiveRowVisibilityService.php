<?php

namespace App\Services\Dashboard;

use App\Enums\OperationQueue;
use App\Models\Incident;
use App\Models\User;
use App\Services\DashboardPersonalizationService;
use App\Services\DashboardService;
use App\Services\Operations\OperationsQueueClassifier;

class DashboardLiveRowVisibilityService
{
    public const ACTION_ADD = 'add';

    public const ACTION_UPDATE = 'update';

    public const ACTION_REMOVE = 'remove';

    public const ACTION_IGNORE = 'ignore';

    public function __construct(
        private readonly DashboardService $dashboardService,
        private readonly DashboardPersonalizationService $dashboardPersonalization,
        private readonly OperationsQueueClassifier $queueClassifier,
    ) {}

    /**
     * @return array{
     *     queue: string,
     *     list_actions: array<string, string>,
     *     html: ?string,
     * }
     */
    public function rowBroadcastPayload(Incident $incident, User $recipient): array
    {
        $incidentQueue = $this->queueClassifier->classify($incident)->value;
        $listActions = $this->listActionsForRecipient($incident, $recipient, $incidentQueue);
        $needsHtml = collect($listActions)->contains(
            fn (string $action): bool => in_array($action, [self::ACTION_ADD, self::ACTION_UPDATE], true),
        );

        return [
            'queue' => $incidentQueue,
            'list_actions' => $listActions,
            'html' => $needsHtml
                ? $this->renderRow($incident, $recipient, $incidentQueue)
                : null,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function listActionsForRecipient(Incident $incident, User $recipient, ?string $incidentQueue = null): array
    {
        $incidentQueue ??= $this->queueClassifier->classify($incident)->value;
        $queues = $this->queuesForRecipient($recipient, $incidentQueue);
        $actions = [];

        foreach ($queues as $queue) {
            $actions[$queue] = $this->listActionForQueue($incident, $recipient, $queue, $incidentQueue);
        }

        return $actions;
    }

    public function isVisibleInQueue(Incident $incident, User $recipient, string $queue): bool
    {
        $scopeUser = $this->dashboardPersonalization->resolveAssignedToScope($recipient, $queue);

        return $this->dashboardService->snapshot()
            ->incidentsForQueue($queue, $scopeUser)
            ->contains(fn (Incident $case): bool => $case->id === $incident->id);
    }

    /**
     * Build targeted row fragments for Hybrid Realtime clients.
     *
     * @param  list<int>  $incidentIds
     * @return array{
     *     rows: list<array{incident_id: int, html: string}>,
     *     remove_incident_ids: list<int>,
     * }
     */
    public function liveRowsPayload(array $incidentIds, User $recipient, string $activeQueue): array
    {
        $rows = [];
        $removeIncidentIds = [];

        if ($incidentIds === []) {
            return [
                'rows' => $rows,
                'remove_incident_ids' => $removeIncidentIds,
            ];
        }

        $incidents = Incident::query()
            ->with([
                'order.transactionAssigner',
                'creator',
                'assignee.roles',
                'activeWaitingState',
                'activeBusinessHold',
                'supportAppointments',
            ])
            ->whereIn('id', $incidentIds)
            ->get()
            ->keyBy('id');

        foreach ($incidentIds as $incidentId) {
            $incident = $incidents->get($incidentId);

            if ($incident === null || ! $recipient->can('view', $incident)) {
                $removeIncidentIds[] = (int) $incidentId;

                continue;
            }

            $incidentQueue = $this->queueClassifier->classify($incident)->value;
            $action = $this->listActionForQueue($incident, $recipient, $activeQueue, $incidentQueue);

            if ($action === self::ACTION_REMOVE) {
                $removeIncidentIds[] = (int) $incidentId;

                continue;
            }

            if (in_array($action, [self::ACTION_ADD, self::ACTION_UPDATE], true)) {
                $rows[] = [
                    'incident_id' => (int) $incidentId,
                    'html' => $this->renderRow($incident, $recipient, $activeQueue),
                ];
            }
        }

        return [
            'rows' => $rows,
            'remove_incident_ids' => array_values(array_unique($removeIncidentIds)),
        ];
    }

    /**
     * @return list<string>
     */
    private function queuesForRecipient(User $recipient, string $incidentQueue): array
    {
        $queues = $this->dashboardPersonalization->availableQueuesFor($recipient);

        if (! in_array($incidentQueue, $queues, true)) {
            $queues[] = $incidentQueue;
        }

        return array_values(array_unique($queues));
    }

    private function listActionForQueue(
        Incident $incident,
        User $recipient,
        string $queue,
        string $incidentQueue,
    ): string {
        if (! $this->isVisibleInQueue($incident, $recipient, $queue)) {
            return self::ACTION_REMOVE;
        }

        if ($queue === OperationQueue::MyWork->value || $queue === $incidentQueue) {
            return self::ACTION_ADD;
        }

        return self::ACTION_UPDATE;
    }

    private function renderRow(Incident $incident, User $recipient, string $dashboardOperationQueue): string
    {
        return view(
            'dashboard.partials.service-case-row',
            $this->dashboardService->serviceCaseRowViewData(
                $incident,
                $recipient,
                $dashboardOperationQueue,
            ),
        )->render();
    }
}
