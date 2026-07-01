<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateSettingsAssignmentRequest;
use App\Http\Requests\UpdateSettingsGeneralRequest;
use App\Http\Requests\UpdateSettingsNotificationsRequest;
use App\Http\Requests\UpdateSettingsSearchRequest;
use App\Http\Requests\UpdateSettingsSlaRequest;
use App\Models\SettingProduct;
use App\Services\ApplicationSettingsService;
use Illuminate\Http\RedirectResponse;

class SettingsSectionController extends Controller
{
    public function __construct(
        private readonly ApplicationSettingsService $applicationSettingsService,
    ) {
        $this->middleware(function ($request, $next) {
            $this->authorize('update', SettingProduct::class);

            return $next($request);
        });
    }

    public function updateGeneral(UpdateSettingsGeneralRequest $request): RedirectResponse
    {
        $this->applicationSettingsService->updateGeneral($request->validated());

        return redirect()
            ->route('settings.index', ['tab' => 'general'])
            ->with('status', 'settings-general-updated');
    }

    public function updateAssignment(UpdateSettingsAssignmentRequest $request): RedirectResponse
    {
        $this->applicationSettingsService->updateAssignment($request->validated());

        return redirect()
            ->route('settings.index', ['tab' => 'assignment'])
            ->with('status', 'settings-assignment-updated');
    }

    public function updateNotifications(UpdateSettingsNotificationsRequest $request): RedirectResponse
    {
        $this->applicationSettingsService->updateNotifications($request->validated());

        return redirect()
            ->route('settings.index', ['tab' => 'notifications'])
            ->with('status', 'settings-notifications-updated');
    }

    public function updateSla(UpdateSettingsSlaRequest $request): RedirectResponse
    {
        $this->applicationSettingsService->updateSla($request->validated());

        return redirect()
            ->route('settings.index', ['tab' => 'sla'])
            ->with('status', 'settings-sla-updated');
    }

    public function updateSearch(UpdateSettingsSearchRequest $request): RedirectResponse
    {
        $this->applicationSettingsService->updateSearch($request->validated());

        return redirect()
            ->route('settings.index', ['tab' => 'search'])
            ->with('status', 'settings-search-updated');
    }
}
