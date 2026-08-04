<?php

namespace App\Services\Dashboard;

use App\Models\User;
use App\Services\Incidents\IncidentListingQuery;
use App\Services\Refunds\RefundListingQuery;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class OperationsWorkspacePanelService
{
    public function __construct(
        private readonly IncidentListingQuery $incidentListingQuery,
        private readonly RefundListingQuery $refundListingQuery,
        private readonly OperationsWorkspaceResolver $resolver,
    ) {}

    /**
     * @return array{
     *     workspace: string,
     *     kind: string,
     *     panel_title: string,
     *     panel_html: string,
     *     supports_live: bool,
     * }
     */
    public function renderEmbedded(User $user, Request $request): array
    {
        $workspace = $request->query('workspace');

        if (! is_string($workspace) || ! $this->resolver->isEmbeddedWorkspace($workspace)) {
            throw new NotFoundHttpException('Unknown embedded workspace.');
        }

        if (! $this->resolver->phase2EmbedEnabled()) {
            throw new NotFoundHttpException('Embedded workspaces are disabled.');
        }

        return match ($workspace) {
            OperationsWorkspaceResolver::WORKSPACE_ACTIVE_CASES => $this->renderActiveCases($user, $request),
            OperationsWorkspaceResolver::WORKSPACE_REFUNDS => $this->renderRefunds($user, $request),
            default => throw new NotFoundHttpException('Unknown embedded workspace.'),
        };
    }

    /**
     * @return array{
     *     workspace: string,
     *     kind: string,
     *     panel_title: string,
     *     panel_html: string,
     *     supports_live: bool,
     * }
     */
    private function renderActiveCases(User $user, Request $request): array
    {
        if (! $user->can('incidents.view')) {
            throw new AccessDeniedHttpException();
        }

        $query = $request->query();
        if (! array_key_exists('status', $query) || $query['status'] === null || $query['status'] === '') {
            $request->query->set('status', 'active');
        }

        $incidents = $this->incidentListingQuery->paginate($request);
        $incidents->setPath(route('dashboard'));
        $incidents->appends(array_filter([
            'workspace' => OperationsWorkspaceResolver::WORKSPACE_ACTIVE_CASES,
            ...$this->incidentListingQuery->filtersFrom($request),
        ], fn ($value) => $value !== null && $value !== ''));

        $viewData = [
            'incidents' => $incidents,
            'categories' => $this->incidentListingQuery->categories(),
            'filters' => $this->incidentListingQuery->filtersFrom($request),
            'workspace' => OperationsWorkspaceResolver::WORKSPACE_ACTIVE_CASES,
            'formAction' => route('dashboard.workspace'),
            'clearUrl' => route('dashboard.workspace', [
                'workspace' => OperationsWorkspaceResolver::WORKSPACE_ACTIVE_CASES,
                'status' => 'active',
            ]),
        ];

        $html = $this->resolver->phase3NativeLayoutEnabled()
            ? view('dashboard.partials.active-cases-workspace', $viewData)->render()
            : view('incidents.partials.index-listing', [
                ...$viewData,
                'embedded' => true,
            ])->render();

        return [
            'workspace' => OperationsWorkspaceResolver::WORKSPACE_ACTIVE_CASES,
            'kind' => 'embedded',
            'panel_title' => 'Active Service Cases',
            'panel_html' => $html,
            'supports_live' => false,
        ];
    }

    /**
     * @return array{
     *     workspace: string,
     *     kind: string,
     *     panel_title: string,
     *     panel_html: string,
     *     supports_live: bool,
     * }
     */
    private function renderRefunds(User $user, Request $request): array
    {
        if (! $user->can('refunds.view')) {
            throw new AccessDeniedHttpException();
        }

        $query = $request->query();
        if (! array_key_exists('status', $query) && ! array_key_exists('queue', $query)) {
            $request->query->set('status', 'pending');
        }

        $refunds = $this->refundListingQuery->paginate($request);
        $refunds->setPath(route('dashboard'));
        $refunds->appends(array_filter([
            'workspace' => OperationsWorkspaceResolver::WORKSPACE_REFUNDS,
            ...$this->refundListingQuery->filtersFrom($request),
        ], fn ($value) => $value !== null && $value !== ''));

        $viewData = [
            'refunds' => $refunds,
            'requesters' => $this->refundListingQuery->requesters(),
            'queueCounts' => $this->refundListingQuery->queueCounts(),
            'activeQueue' => $request->string('queue')->trim()->toString(),
            'filters' => $this->refundListingQuery->filtersFrom($request),
            'workspace' => OperationsWorkspaceResolver::WORKSPACE_REFUNDS,
            'formAction' => route('dashboard.workspace'),
            'clearUrl' => route('dashboard.workspace', [
                'workspace' => OperationsWorkspaceResolver::WORKSPACE_REFUNDS,
                'status' => 'pending',
            ]),
        ];

        $html = $this->resolver->phase3NativeLayoutEnabled()
            ? view('dashboard.partials.refunds-workspace', $viewData)->render()
            : view('refunds.partials.index-listing', [
                ...$viewData,
                'embedded' => true,
            ])->render();

        return [
            'workspace' => OperationsWorkspaceResolver::WORKSPACE_REFUNDS,
            'kind' => 'embedded',
            'panel_title' => 'Refund Queue',
            'panel_html' => $html,
            'supports_live' => false,
        ];
    }
}
