<?php

namespace App\Services;

use App\Data\Workspace\WorkspaceRefreshEffects;
use App\Data\Workspace\WorkspaceRequestContext;
use App\Enums\WorkspaceComponent;
use App\Models\Incident;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

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
            $canManageTransactions = $user->hasAnyRole([
                RolePermissionSeeder::ROLE_ADMIN,
                RolePermissionSeeder::ROLE_SUPERADMIN,
            ]);

            $refresh['replace_row'] = [
                'incident_id' => $incident->id,
                'html' => view('dashboard.partials.service-case-row', [
                    'serviceCase' => $incident,
                    'canManageTransactions' => $canManageTransactions,
                    'canSelectRows' => $canManageTransactions,
                ])->render(),
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
