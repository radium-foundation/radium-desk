<?php

namespace App\Services;

use App\Models\DeviceModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class DeviceModelSettingsService
{
    public function paginated(?string $search = null, int $perPage = 15): LengthAwarePaginator
    {
        return DeviceModel::query()
            ->when(filled($search), function ($query) use ($search): void {
                $term = '%'.trim((string) $search).'%';
                $query->where(function ($innerQuery) use ($term): void {
                    $innerQuery->where('name', 'like', $term)
                        ->orWhere('code', 'like', $term)
                        ->orWhere('brand', 'like', $term);
                });
            })
            ->orderBy('display_order')
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function create(
        string $name,
        ?string $code,
        ?string $brand,
        int $displayOrder,
        ?string $driverDownloadUrl = null,
        ?string $buyDeviceUrl = null,
        ?string $buyRdServiceUrl = null,
    ): DeviceModel {
        return DB::transaction(fn (): DeviceModel => DeviceModel::query()->create([
            'name' => $name,
            'code' => $code,
            'brand' => $brand,
            'driver_download_url' => $driverDownloadUrl,
            'buy_device_url' => $buyDeviceUrl,
            'buy_rd_service_url' => $buyRdServiceUrl,
            'display_order' => $displayOrder,
            'is_active' => true,
        ]));
    }

    public function update(
        DeviceModel $deviceModel,
        string $name,
        ?string $code,
        ?string $brand,
        int $displayOrder,
        ?string $driverDownloadUrl = null,
        ?string $buyDeviceUrl = null,
        ?string $buyRdServiceUrl = null,
    ): DeviceModel {
        $deviceModel->update([
            'name' => $name,
            'code' => $code,
            'brand' => $brand,
            'driver_download_url' => $driverDownloadUrl,
            'buy_device_url' => $buyDeviceUrl,
            'buy_rd_service_url' => $buyRdServiceUrl,
            'display_order' => $displayOrder,
        ]);

        return $deviceModel->fresh();
    }

    public function toggle(DeviceModel $deviceModel, bool $active): DeviceModel
    {
        $deviceModel->update(['is_active' => $active]);

        return $deviceModel->fresh();
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function optionsForOrderForm(?int $includeInactiveId = null): array
    {
        return DeviceModel::query()
            ->where(function ($query) use ($includeInactiveId): void {
                $query->where('is_active', true);

                if ($includeInactiveId !== null) {
                    $query->orWhere('id', $includeInactiveId);
                }
            })
            ->orderBy('display_order')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (DeviceModel $model): array => [
                'id' => $model->id,
                'name' => $model->name,
            ])
            ->all();
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function activeOptions(): array
    {
        return DeviceModel::query()
            ->where('is_active', true)
            ->orderBy('display_order')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (DeviceModel $model): array => [
                'id' => $model->id,
                'name' => $model->name,
            ])
            ->all();
    }
}
