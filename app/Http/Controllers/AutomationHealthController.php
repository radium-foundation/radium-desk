<?php

namespace App\Http\Controllers;

use App\Models\AutomationExecution;
use App\Services\Operations\AutomationHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AutomationHealthController extends Controller
{
    public function __construct(
        private readonly AutomationHealthService $healthService,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless($request->user()?->can('automation-operations.view'), 403);

            return $next($request);
        });
    }

    public function index(Request $request): View
    {
        return view('admin.automation-health.index', [
            'dashboard' => $this->healthService->dashboardData($request->only([
                'automation_type',
                'status',
                'date',
                'search',
            ])),
        ]);
    }

    public function show(AutomationExecution $execution): JsonResponse
    {
        return response()->json([
            'execution' => $this->healthService->executionDetail($execution),
        ]);
    }
}
