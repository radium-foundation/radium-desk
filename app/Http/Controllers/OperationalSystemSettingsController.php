<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateOperationalSystemSettingsRequest;
use App\Models\SystemSetting;
use App\Services\Performance\PerformanceHealthService;
use App\Services\Performance\PerformanceSettingsService;
use App\Services\SystemSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class OperationalSystemSettingsController extends Controller
{
    public function __construct(
        private readonly SystemSettingsService $systemSettingsService,
        private readonly PerformanceSettingsService $performanceSettingsService,
        private readonly PerformanceHealthService $performanceHealthService,
    ) {
        $this->middleware(function ($request, $next) {
            $this->authorize('viewAny', SystemSetting::class);

            return $next($request);
        });
    }

    public function index(): View
    {
        $groupedSettings = $this->systemSettingsService->groupedForAdmin()
            ->except('performance');

        return view('admin.system-settings.index', [
            'categories' => config('system_settings.categories', []),
            'groupedSettings' => $groupedSettings,
            'performanceProfiles' => $this->performanceSettingsService->profiles(),
            'performanceProfile' => $this->performanceSettingsService->currentProfile(),
            'performancePollingSettings' => $this->performanceSettingsService->pollingSettingsForAdmin(),
            'performanceHybridRealtimeSettings' => $this->performanceSettingsService->hybridRealtimeSettingsForAdmin(),
            'performanceNotificationSettings' => $this->performanceSettingsService->notificationSettingsForAdmin(),
            'performanceHealth' => $this->performanceHealthService->snapshot(),
        ]);
    }

    public function update(UpdateOperationalSystemSettingsRequest $request): RedirectResponse
    {
        $this->authorize('update', SystemSetting::class);

        $resolved = $this->performanceSettingsService->resolveForSave(
            $request->validatedSettings(),
        );

        $this->systemSettingsService->setMany(
            $resolved,
            $request->user(),
        );

        return redirect()
            ->route('admin.system-settings.index')
            ->with('status', 'operational-system-settings-updated');
    }
}
