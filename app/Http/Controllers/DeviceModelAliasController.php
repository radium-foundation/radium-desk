<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDeviceModelAliasRequest;
use App\Http\Requests\UpdateDeviceModelAliasRequest;
use App\Models\DeviceModel;
use App\Models\DeviceModelAlias;
use App\Services\DeviceModelAliasSettingsService;
use Illuminate\Http\RedirectResponse;

class DeviceModelAliasController extends Controller
{
    public function __construct(
        private readonly DeviceModelAliasSettingsService $deviceModelAliasSettingsService,
    ) {
        $this->middleware(function ($request, $next) {
            $this->authorize('update', DeviceModel::class);

            return $next($request);
        });
    }

    public function store(StoreDeviceModelAliasRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $this->deviceModelAliasSettingsService->create(
            deviceModelId: (int) $validated['device_model_id'],
            alias: $validated['alias'],
        );

        return redirect()
            ->route('settings.index', [
                'tab' => 'device-models',
                'alias_search' => request('alias_search'),
            ])
            ->with('status', 'device-model-alias-created');
    }

    public function update(UpdateDeviceModelAliasRequest $request, DeviceModelAlias $deviceModelAlias): RedirectResponse
    {
        $validated = $request->validated();

        $this->deviceModelAliasSettingsService->update(
            deviceModelAlias: $deviceModelAlias,
            deviceModelId: (int) $validated['device_model_id'],
            alias: $validated['alias'],
        );

        return redirect()
            ->route('settings.index', [
                'tab' => 'device-models',
                'alias_search' => request('alias_search'),
            ])
            ->with('status', 'device-model-alias-updated');
    }

    public function destroy(DeviceModelAlias $deviceModelAlias): RedirectResponse
    {
        $this->deviceModelAliasSettingsService->delete($deviceModelAlias);

        return redirect()
            ->route('settings.index', [
                'tab' => 'device-models',
                'alias_search' => request('alias_search'),
            ])
            ->with('status', 'device-model-alias-deleted');
    }
}
