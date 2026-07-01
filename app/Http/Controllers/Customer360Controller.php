<?php

namespace App\Http\Controllers;

use App\Models\Incident;
use App\Services\Customer360Service;
use App\Services\Timeline\Customer360TimelineService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class Customer360Controller extends Controller
{
    public function __construct(
        private readonly Customer360Service $customer360Service,
        private readonly Customer360TimelineService $customer360TimelineService,
    ) {}

    public function show(Incident $incident): Response
    {
        $this->authorize('view', $incident);

        $html = view('customer-360.drawer-content', $this->customer360Service->drawerData($incident))->render();

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public function timeline(Incident $incident, Request $request): Response
    {
        $this->authorize('view', $incident);

        $offset = max(0, (int) $request->query('offset', 0));
        $viewModel = $this->customer360TimelineService->forIncident($incident, $offset);

        $html = view('customer-360.partials.timeline-page', [
            'viewModel' => $viewModel,
            'loadMoreUrl' => route('dashboard.service-cases.customer-360.timeline', $incident),
        ])->render();

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
