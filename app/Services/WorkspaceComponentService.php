<?php

namespace App\Services;

use App\Data\Workspace\WorkspaceRequestContext;
use App\Enums\IncidentStatus;
use App\Enums\ServiceCaseCloseExceptionReason;
use App\Enums\WorkspaceActionType;
use App\Enums\WorkspaceComponent;
use App\Models\Incident;
use App\Models\Remark;
use App\Models\User;
use App\Services\DeviceModelSettingsService;
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
            WorkspaceComponent::Action => $this->canUseActionDialog($incident, $user),
            WorkspaceComponent::Remark => $user->can('create', Remark::class),
            WorkspaceComponent::Resolve => $user->can('update', $incident)
                && $incident->status !== IncidentStatus::Closed,
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
            WorkspaceComponent::Action => [
                'incident' => $incident,
                'reassignableAdmins' => $this->assignmentService->reassignableAdmins(),
                'actionCapabilities' => $this->actionCapabilities($incident, auth()->user()),
                'selectedAction' => WorkspaceActionType::tryFrom((string) request()->query('action'))
                    ?? ($incident->status === IncidentStatus::Closed
                        ? WorkspaceActionType::Reopen
                        : WorkspaceActionType::Assign),
                'exceptionReasons' => ServiceCaseCloseExceptionReason::cases(),
                ...$this->actionWorkspaceFields($requestContext, $incident),
                ...$this->actionRemarkUsers(),
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
                ...$this->actionRemarkUsers(),
            ],
            WorkspaceComponent::Close => [
                'incident' => $incident,
                ...$this->statusWorkspaceFields(WorkspaceComponent::Close, $requestContext, $incident),
                ...$this->actionRemarkUsers(),
            ],
            WorkspaceComponent::Timeline => [
                'incident' => $incident,
                'activityTimeline' => $this->activityTimelineService->forIncident($incident),
            ],
            WorkspaceComponent::BatchTransaction => [],
            WorkspaceComponent::BatchDeviceModel => [],
        };
    }

    /**
     * @param  list<int>  $incidentIds
     * @return array<string, mixed>
     */
    public function batchTransactionViewData(
        array $incidentIds,
        WorkspaceRequestContext $requestContext,
    ): array {
        $incidents = Incident::query()
            ->with('order')
            ->whereIn('id', $incidentIds)
            ->get()
            ->sortBy(fn (Incident $incident): int => array_search($incident->id, $incidentIds, true) ?: PHP_INT_MAX)
            ->values();

        return [
            'incidents' => $incidents,
            'selectedCount' => count($incidentIds),
            'workspaceActionUrl' => route('dashboard.workspace.batch-transaction'),
            'workspaceContext' => $requestContext->context->value,
            'incidentIds' => $incidentIds,
        ];
    }

    /**
     * @param  list<int>  $incidentIds
     * @return array<string, mixed>
     */
    public function batchDeviceModelViewData(
        array $incidentIds,
        WorkspaceRequestContext $requestContext,
    ): array {
        $incidents = Incident::query()
            ->with('order')
            ->whereIn('id', $incidentIds)
            ->get()
            ->sortBy(fn (Incident $incident): int => array_search($incident->id, $incidentIds, true) ?: PHP_INT_MAX)
            ->values();

        return [
            'incidents' => $incidents,
            'selectedCount' => count($incidentIds),
            'deviceModels' => app(DeviceModelSettingsService::class)->activeOptions(),
            'workspaceActionUrl' => route('dashboard.workspace.batch-device-model'),
            'workspaceContext' => $requestContext->context->value,
            'incidentIds' => $incidentIds,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function actionWorkspaceFields(?WorkspaceRequestContext $requestContext, Incident $incident): array
    {
        if ($requestContext === null) {
            return [];
        }

        return [
            'workspaceActionUrl' => route('incidents.workspace.action', $incident),
            'workspaceContext' => $requestContext->context->value,
        ];
    }

    private function canUseActionDialog(Incident $incident, User $user): bool
    {
        $capabilities = $this->actionCapabilities($incident, $user);

        return $capabilities['assign'] || $capabilities['close'] || $capabilities['reopen'];
    }

    /**
     * @return array<string, bool>
     */
    private function actionCapabilities(Incident $incident, User $user): array
    {
        $canUpdate = $user->can('update', $incident);
        $isClosed = $incident->status === IncidentStatus::Closed;

        return [
            'assign' => $user->can('reassign', $incident) && ! $isClosed,
            'close' => $canUpdate && ! $isClosed,
            'reopen' => $canUpdate && $isClosed,
        ];
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

    /**
     * @return array<string, mixed>
     */
    private function actionRemarkUsers(): array
    {
        return [
            'mentionUsers' => User::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->pluck('name'),
        ];
    }
}
