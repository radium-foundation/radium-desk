<?php

namespace App\Http\Controllers;

use App\Services\AutomationOperationsService;
use Illuminate\View\View;

class AutomationOperationsController extends Controller
{
    public function __construct(
        private readonly AutomationOperationsService $operationsService,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless($request->user()?->can('automation-operations.view'), 403);

            return $next($request);
        });
    }

    public function index(): View
    {
        return view('admin.automation.index', [
            'dashboard' => $this->operationsService->dashboardData(),
        ]);
    }
}
