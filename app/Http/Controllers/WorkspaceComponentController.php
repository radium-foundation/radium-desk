<?php

namespace App\Http\Controllers;

use App\Models\Incident;
use App\Enums\WorkspaceComponent;
use App\Services\CommunicationActions\CommunicationActionLifecycleService;
use App\Services\WorkspaceComponentService;
use App\Services\WorkspaceContextResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WorkspaceComponentController extends Controller
{
    public function __construct(
        private readonly WorkspaceComponentService $workspaceComponentService,
        private readonly WorkspaceContextResolver $contextResolver,
        private readonly CommunicationActionLifecycleService $communicationActionLifecycleService,
    ) {}

    public function show(Request $request, Incident $incident, string $component): Response
    {
        $this->authorize('view', $incident);

        $workspaceComponent = $this->workspaceComponentService->resolve($component);

        $this->workspaceComponentService->authorize(
            $workspaceComponent,
            $incident,
            $request->user(),
        );

        $requestContext = $this->contextResolver->resolve($request, $incident);

        if ($workspaceComponent === WorkspaceComponent::CommunicationAction) {
            $actionKey = (string) $request->query('key', '');

            if ($actionKey !== '') {
                $this->communicationActionLifecycleService->recordOpened(
                    incident: $incident,
                    actor: $request->user(),
                    actionKey: $actionKey,
                    request: $request,
                );
            }
        }

        $html = view(
            $this->workspaceComponentService->view($workspaceComponent),
            $this->workspaceComponentService->viewData(
                $workspaceComponent,
                $incident,
                $requestContext,
            ),
        )->render();

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }
}
