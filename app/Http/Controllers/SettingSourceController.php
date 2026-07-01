<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSettingSourceRequest;
use App\Http\Requests\UpdateSettingSourceRequest;
use App\Models\SettingProduct;
use App\Models\SettingSource;
use App\Services\ApplicationSettingsService;
use Illuminate\Http\RedirectResponse;

class SettingSourceController extends Controller
{
    public function __construct(
        private readonly ApplicationSettingsService $applicationSettingsService,
    ) {
        $this->middleware(function ($request, $next) {
            $this->authorize('update', SettingProduct::class);

            return $next($request);
        });
    }

    public function store(StoreSettingSourceRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $this->applicationSettingsService->createSource(
            $validated['key'],
            $validated['label'],
            $validated['icon'],
            (int) $validated['sort_order'],
        );

        return redirect()
            ->route('settings.index', ['tab' => 'sources'])
            ->with('status', 'settings-source-created');
    }

    public function update(UpdateSettingSourceRequest $request, SettingSource $source): RedirectResponse
    {
        $validated = $request->validated();

        $this->applicationSettingsService->updateSource(
            $source,
            $validated['label'],
            $validated['icon'],
            (int) $validated['sort_order'],
        );

        return redirect()
            ->route('settings.index', ['tab' => 'sources'])
            ->with('status', 'settings-source-updated');
    }

    public function toggle(SettingSource $source): RedirectResponse
    {
        $this->applicationSettingsService->toggleSource($source, ! $source->is_enabled);

        return redirect()
            ->route('settings.index', ['tab' => 'sources'])
            ->with('status', $source->fresh()->is_enabled ? 'settings-source-enabled' : 'settings-source-disabled');
    }
}
