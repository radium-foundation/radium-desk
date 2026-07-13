<?php

namespace App\Contracts\CommunicationActions;

use App\Data\CommunicationActions\CommunicationActionTarget;
use App\Enums\CommunicationActionKey;
use App\Models\Incident;

interface CommunicationActionTargetProvider
{
    public function supports(CommunicationActionKey $key): bool;

    public function targetGroupLabel(): string;

    /**
     * @return list<CommunicationActionTarget>
     */
    public function targets(Incident $incident): array;

    public function defaultTargetValue(Incident $incident): ?string;
}
