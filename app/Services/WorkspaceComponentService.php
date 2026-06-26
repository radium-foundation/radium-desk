<?php

namespace App\Services;

use App\Data\Workspace\WorkspaceRequestContext;
use App\Enums\IncidentStatus;
use App\Enums\WorkspaceComponent;
use App\Models\Incident;
use App\Models\Remark;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class WorkspaceComponentService
{
    public function __construct(
        private readonly ServiceCaseAssignmentService $assignmentService,
        private readonly ServiceCaseActivityTimelineService $activityTimelineService,
    ) {}

    public function resolve(string $component): WorkspaceComponent
    {
        $resolved = WorkspaceComponent::tryFrom($component);

        if ($resolved === null) {
            abort(404);
        }

        return $resolved;
    }

    public function authorize(WorkspaceComponent $component, Incident $incident, User $user): void
    {
        $authorized = match ($component) {
            WorkspaceComponent::Assign => $user->can('reassign', $incident),
            WorkspaceComponent::Remark => $user->can('create', Remark::class),
            WorkspaceComponent::Resolve => $user->can('update', $incident)
                && ! in_array($incident->status, [IncidentStatus::Resolved, IncidentStatus::Closed], true),
            WorkspaceComponent::Close => $user->can('update', $incident)
                && $incident->status !== IncidentStatus::Closed,
            WorkspaceComponent::Timeline => $user->can('view', $incident),
        };

        if (! $authorized) {
            throw new AuthorizationException('This action is unauthorized.');
        }
    }

    public function view(WorkspaceComponent $component): string
    {
        return $component->view();
    }

    /**
     * @return array<string, mixed>
     */
    public function viewData(
        WorkspaceComponent $component,
        Incident $incident,
        ?WorkspaceRequestContext $requestContext = null,
    ): array {
        return match ($component) {
            WorkspaceComponent::Assign => [
                'incident' => $incident,
                'reassignableAdmins' => $this->assignmentService->reassignableAdmins(),
                ...$this->assignWorkspaceFields($requestContext, $incident),
            ],
            WorkspaceComponent::Remark => [
                'incident' => $incident,
                'mentionUsers' => User::query()
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->pluck('name'),
                ...$this->remarkWorkspaceFields($requestContext, $incident),
            ],
            WorkspaceComponent::Resolve => [
                'incident' => $incident,
                ...$this->statusWorkspaceFields(WorkspaceComponent::Resolve, $requestContext, $incident),
            ],
            WorkspaceComponent::Close => [
                'incident' => $incident,
                ...$this->statusWorkspaceFields(WorkspaceComponent::Close, $requestContext, $incident),
            ],
            WorkspaceComponent::Timeline => [
                'incident' => $incident,
                'activityTimeline' => $this->activityTimelineService->forIncident($incident),
            ],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function assignWorkspaceFields(?WorkspaceRequestContext $requestContext, Incident $incident): array
    {
        if ($requestContext === null) {
            return [];
        }

        return [
            'workspaceActionUrl' => route('incidents.workspace.assign', $incident),
            'workspaceContext' => $requestContext->context->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function remarkWorkspaceFields(?WorkspaceRequestContext $requestContext, Incident $incident): array
    {
        if ($requestContext === null) {
            return [];
        }

        return [
            'workspaceActionUrl' => route('incidents.workspace.remark', $incident),
            'workspaceContext' => $requestContext->context->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function statusWorkspaceFields(
        WorkspaceComponent $component,
        ?WorkspaceRequestContext $requestContext,
        Incident $incident,
    ): array {
        if ($requestContext === null) {
            return [];
        }

        $route = match ($component) {
            WorkspaceComponent::Resolve => 'incidents.workspace.resolve',
            WorkspaceComponent::Close => 'incidents.workspace.close',
            default => null,
        };

        if ($route === null) {
            return [];
        }

        return [
            'workspaceActionUrl' => route($route, $incident),
            'workspaceContext' => $requestContext->context->value,
        ];
    }
}
