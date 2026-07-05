<?php

namespace App\Services;

use App\Data\Workspace\WorkspaceRefreshEffects;
use App\Data\Workspace\WorkspaceRequestContext;
use App\Enums\WorkspaceActionType;
use App\Enums\WorkspaceComponent;
use App\Models\Incident;
use App\Models\User;

class WorkspaceRefreshRenderer
{
    public function __construct(
        private readonly ServiceCaseActivityTimelineService $activityTimelineService,
        private readonly DashboardService $dashboardService,
        private readonly WorkspaceComponentService $componentService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function buildRefreshPayload(
        WorkspaceRefreshEffects $effects,
        WorkspaceComponent $component,
        Incident $incident,
        User $user,
    ): array {
        $incident->loadMissing(['order.transactionAssigner', 'creator', 'assignee', 'updater']);

        $refresh = [
            'kpis' => $effects->refreshKpis,
            'targets' => [],
            'fragments' => [],
        ];

        if ($effects->refreshKpis) {
            $stats = $this->dashboardService->statsFor($user);
            $refresh['kpis_html'] = [
                'kpi_strip_html' => $this->dashboardService->renderKpiStrip($stats, $user),
            ];
        }

        if ($effects->replaceRow) {
            $refresh['replace_row'] = [
                'incident_id' => $incident->id,
                'html' => view(
                    'dashboard.partials.service-case-row',
                    $this->dashboardService->serviceCaseRowViewData($incident, $user),
                )->render(),
                'strategy' => 'replace',
            ];
        }

        foreach ($effects->targetSelectors as $selector) {
            $html = $this->renderTarget($selector, $incident);

            if ($html === null) {
                continue;
            }

            $refresh['targets'][] = [
                'selector' => $selector,
                'html' => $html,
                'strategy' => 'outerHTML',
            ];
        }

        return $refresh;
    }

    public function renderActionFragment(
        Incident $incident,
        WorkspaceRequestContext $requestContext,
        WorkspaceActionType $selectedAction,
        array $payload = [],
    ): string {
        return view(
            $this->componentService->view(WorkspaceComponent::Action),
            [
                ...$this->componentService->viewData(
                    WorkspaceComponent::Action,
                    $incident,
                    $requestContext,
                ),
                'selectedAction' => $selectedAction,
                'formPayload' => $payload,
            ],
        )->render();
    }

    public function renderAssignFragment(Incident $incident, WorkspaceRequestContext $requestContext): string
    {
        return view(
            $this->componentService->view(WorkspaceComponent::Assign),
            $this->componentService->viewData(
                WorkspaceComponent::Assign,
                $incident,
                $requestContext,
            ),
        )->render();
    }

    public function renderRemarkFragment(
        Incident $incident,
        WorkspaceRequestContext $requestContext,
        ?string $body = null,
    ): string {
        return view(
            $this->componentService->view(WorkspaceComponent::Remark),
            [
                ...$this->componentService->viewData(
                    WorkspaceComponent::Remark,
                    $incident,
                    $requestContext,
                ),
                'remarkBody' => $body ?? old('body'),
            ],
        )->render();
    }

    public function renderResolveFragment(
        Incident $incident,
        WorkspaceRequestContext $requestContext,
        ?string $body = null,
    ): string {
        return view(
            $this->componentService->view(WorkspaceComponent::Resolve),
            [
                ...$this->componentService->viewData(
                    WorkspaceComponent::Resolve,
                    $incident,
                    $requestContext,
                ),
                'remarkBody' => $body ?? old('body'),
            ],
        )->render();
    }

    public function renderCloseFragment(
        Incident $incident,
        WorkspaceRequestContext $requestContext,
        ?string $body = null,
    ): string {
        return view(
            $this->componentService->view(WorkspaceComponent::Close),
            [
                ...$this->componentService->viewData(
                    WorkspaceComponent::Close,
                    $incident,
                    $requestContext,
                ),
                'remarkBody' => $body ?? old('body'),
            ],
        )->render();
    }

    /**
     * @param  list<int>  $incidentIds
     */
    public function renderBatchTransactionFragment(
        array $incidentIds,
        WorkspaceRequestContext $requestContext,
    ): string {
        return view(
            $this->componentService->view(WorkspaceComponent::BatchTransaction),
            $this->componentService->batchTransactionViewData($incidentIds, $requestContext),
        )->render();
    }

    /**
     * @param  list<int>  $incidentIds
     */
    public function renderBatchDeviceModelFragment(
        array $incidentIds,
        WorkspaceRequestContext $requestContext,
    ): string {
        return view(
            $this->componentService->view(WorkspaceComponent::BatchDeviceModel),
            $this->componentService->batchDeviceModelViewData($incidentIds, $requestContext),
        )->render();
    }

    /**
     * @param  array{
     *     count: int,
     *     transaction_id: string,
     *     rows: array<int, array{incident_id: int, html: string}>,
     *     succeeded_incident_ids: list<int>,
     *     failed_incidents: list<array{incident_id: int, message: string}>
     * }  $result
     * @return array<string, mixed>
     */
    public function buildBatchRefreshPayload(
        WorkspaceRefreshEffects $effects,
        array $result,
        User $user,
    ): array {
        $refresh = [
            'kpis' => $effects->refreshKpis,
            'targets' => [],
            'fragments' => [],
            'replace_rows' => [],
        ];

        if ($effects->refreshKpis) {
            $stats = $this->dashboardService->statsFor($user);
            $refresh['kpis_html'] = [
                'kpi_strip_html' => $this->dashboardService->renderKpiStrip($stats, $user),
            ];
        }

        $succeededIds = array_flip($result['succeeded_incident_ids']);

        foreach ($result['rows'] as $row) {
            if (! isset($succeededIds[$row['incident_id']])) {
                continue;
            }

            $refresh['replace_rows'][] = [
                'incident_id' => $row['incident_id'],
                'html' => $row['html'],
                'strategy' => 'replace',
            ];
        }

        return $refresh;
    }

    private function renderTarget(string $selector, Incident $incident): ?string
    {
        return match ($selector) {
            (string) config('workspace.targets.activity_timeline') => view('incidents.partials.activity-timeline', [
                'incident' => $incident,
                'activityTimeline' => $this->activityTimelineService->forIncident($incident),
            ])->render(),
            (string) config('workspace.targets.service_case_header') => view('incidents.partials.service-case-header', [
                'incident' => $incident,
            ])->render(),
            default => null,
        };
    }
}
