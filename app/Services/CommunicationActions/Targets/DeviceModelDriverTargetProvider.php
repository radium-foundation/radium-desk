<?php

namespace App\Services\CommunicationActions\Targets;

use App\Contracts\CommunicationActions\CommunicationActionTargetProvider;
use App\Data\CommunicationActions\CommunicationActionTarget;
use App\Enums\CommunicationActionKey;
use App\Models\DeviceModel;
use App\Models\Incident;

final class DeviceModelDriverTargetProvider implements CommunicationActionTargetProvider
{
    public function supports(CommunicationActionKey $key): bool
    {
        return $key === CommunicationActionKey::DriverInstallationGuide;
    }

    public function targetGroupLabel(): string
    {
        return 'Device Models';
    }

    public function targets(Incident $incident): array
    {
        return $this->deviceModelsWithUrl('driver_download_url');
    }

    public function defaultTargetValue(Incident $incident): ?string
    {
        $incident->loadMissing('order');
        $orderModelId = $incident->order?->device_model_id;

        if ($orderModelId === null) {
            return $this->firstTargetValue($incident);
        }

        $available = collect($this->targets($incident))->pluck('value');

        if ($available->contains((string) $orderModelId)) {
            return (string) $orderModelId;
        }

        return $this->firstTargetValue($incident);
    }

    /**
     * @return list<CommunicationActionTarget>
     */
    private function deviceModelsWithUrl(string $urlColumn): array
    {
        return DeviceModel::query()
            ->where('is_active', true)
            ->whereNotNull($urlColumn)
            ->where($urlColumn, '!=', '')
            ->orderBy('display_order')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (DeviceModel $model): CommunicationActionTarget => new CommunicationActionTarget(
                value: (string) $model->id,
                label: $model->name,
            ))
            ->values()
            ->all();
    }

    private function firstTargetValue(Incident $incident): ?string
    {
        return $this->targets($incident)[0]->value ?? null;
    }
}
