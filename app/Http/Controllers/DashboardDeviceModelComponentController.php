<?php

namespace App\Http\Controllers;

use App\Http\Requests\DashboardBatchDeviceModelComponentRequest;
use App\Services\WorkspaceContextResolver;
use App\Services\WorkspaceRefreshRenderer;
use Illuminate\Http\Response;

class DashboardDeviceModelComponentController extends Controller
{
    public function __construct(
        private readonly WorkspaceContextResolver $contextResolver,
        private readonly WorkspaceRefreshRenderer $refreshRenderer,
    ) {}

    public function batchAssign(DashboardBatchDeviceModelComponentRequest $request): Response
    {
        $incidentIds = array_map('intval', $request->input('incident_ids'));
        $requestContext = $this->contextResolver->resolve($request);

        $html = $this->refreshRenderer->renderBatchDeviceModelFragment(
            $incidentIds,
            $requestContext,
        );

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }
}
