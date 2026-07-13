<?php

namespace App\Services\CommunicationActions\Targets;

use App\Contracts\CommunicationActions\CommunicationActionTargetProvider;
use App\Data\CommunicationActions\CommunicationActionTarget;
use App\Enums\CommunicationActionKey;
use App\Models\DeviceModel;
use App\Models\Incident;

final class DeviceModelRdServiceTargetProvider implements CommunicationActionTargetProvider
{
    public function supports(CommunicationActionKey $key): bool
    {
        return $key === CommunicationActionKey::BuyRdService;
    }

    public function targetGroupLabel(): string
    {
        return 'Services';
    }

    public function targets(Incident $incident): array
    {
        return DeviceModel::query()
            ->where('is_active', true)
            ->whereNotNull('buy_rd_service_url')
            ->where('buy_rd_service_url', '!=', '')
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

    public function defaultTargetValue(Incident $incident): ?string
    {
        $incident->loadMissing('order');
        $orderModelId = $incident->order?->device_model_id;

        if ($orderModelId === null) {
            return $this->targets($incident)[0]->value ?? null;
        }

        $available = collect($this->targets($incident))->pluck('value');

        if ($available->contains((string) $orderModelId)) {
            return (string) $orderModelId;
        }

        return $this->targets($incident)[0]->value ?? null;
    }
}
