<?php

namespace App\Services;

use App\Data\Workspace\WorkspaceRefreshEffects;
use App\Data\Workspace\WorkspaceRequestContext;
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
                'action_stats_html' => view('dashboard.partials.action-stats', compact('stats'))->render(),
                'sla_cards_html' => view('dashboard.partials.sla-alert-cards', compact('stats'))->render(),
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

    public function renderResolveFragment(Incident $incident, WorkspaceRequestContext $requestContext): string
    {
        return view(
            $this->componentService->view(WorkspaceComponent::Resolve),
            $this->componentService->viewData(
                WorkspaceComponent::Resolve,
                $incident,
                $requestContext,
            ),
        )->render();
    }

    public function renderCloseFragment(Incident $incident, WorkspaceRequestContext $requestContext): string
    {
        return view(
            $this->componentService->view(WorkspaceComponent::Close),
            $this->componentService->viewData(
                WorkspaceComponent::Close,
                $incident,
                $requestContext,
            ),
        )->render();
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
