<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateOperationalSystemSettingsRequest;
use App\Models\SystemSetting;
use App\Services\SystemSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class OperationalSystemSettingsController extends Controller
{
    public function __construct(
        private readonly SystemSettingsService $systemSettingsService,
    ) {
        $this->middleware(function ($request, $next) {
            $this->authorize('viewAny', SystemSetting::class);

            return $next($request);
        });
    }

    public function index(): View
    {
        return view('admin.system-settings.index', [
            'categories' => config('system_settings.categories', []),
            'groupedSettings' => $this->systemSettingsService->groupedForAdmin(),
        ]);
    }

    public function update(UpdateOperationalSystemSettingsRequest $request): RedirectResponse
    {
        $this->authorize('update', SystemSetting::class);

        $this->systemSettingsService->setMany(
            $request->validatedSettings(),
            $request->user(),
        );

        return redirect()
            ->route('admin.system-settings.index')
            ->with('status', 'operational-system-settings-updated');
    }
}
