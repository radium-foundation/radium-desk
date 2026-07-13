<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDeviceModelRequest;
use App\Http\Requests\UpdateDeviceModelRequest;
use App\Models\DeviceModel;
use App\Services\DeviceModelSettingsService;
use Illuminate\Http\RedirectResponse;

class DeviceModelController extends Controller
{
    public function __construct(
        private readonly DeviceModelSettingsService $deviceModelSettingsService,
    ) {
        $this->middleware(function ($request, $next) {
            $this->authorize('update', DeviceModel::class);

            return $next($request);
        });
    }

    public function store(StoreDeviceModelRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $this->deviceModelSettingsService->create(
            name: $validated['name'],
            code: $validated['code'] ?? null,
            brand: $validated['brand'] ?? null,
            displayOrder: (int) $validated['display_order'],
            driverDownloadUrl: $validated['driver_download_url'] ?? null,
            buyDeviceUrl: $validated['buy_device_url'] ?? null,
            buyRdServiceUrl: $validated['buy_rd_service_url'] ?? null,
        );

        return redirect()
            ->route('settings.index', ['tab' => 'device-models'])
            ->with('status', 'device-model-created');
    }

    public function update(UpdateDeviceModelRequest $request, DeviceModel $deviceModel): RedirectResponse
    {
        $validated = $request->validated();

        $this->deviceModelSettingsService->update(
            deviceModel: $deviceModel,
            name: $validated['name'],
            code: $validated['code'] ?? null,
            brand: $validated['brand'] ?? null,
            displayOrder: (int) $validated['display_order'],
            driverDownloadUrl: $validated['driver_download_url'] ?? null,
            buyDeviceUrl: $validated['buy_device_url'] ?? null,
            buyRdServiceUrl: $validated['buy_rd_service_url'] ?? null,
        );

        return redirect()
            ->route('settings.index', ['tab' => 'device-models', 'search' => request('search')])
            ->with('status', 'device-model-updated');
    }

    public function toggle(DeviceModel $deviceModel): RedirectResponse
    {
        $this->deviceModelSettingsService->toggle($deviceModel, ! $deviceModel->is_active);

        return redirect()
            ->route('settings.index', ['tab' => 'device-models', 'search' => request('search')])
            ->with('status', $deviceModel->fresh()->is_active ? 'device-model-activated' : 'device-model-deactivated');
    }
}
