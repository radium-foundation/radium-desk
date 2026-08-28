<?php

namespace App\Services\Operations;

use App\Data\Operations\LatestServiceReference;
use App\Enums\OperationQueue;
use App\Models\User;
use App\ReadModels\Cases\CaseQueueReadModel;
use Illuminate\Support\Carbon;

class IraReadyQueueDigestContextService
{
    public function __construct(
        private readonly CaseQueueReadModel $caseQueueReadModel,
        private readonly LatestServiceReferenceQuery $latestServiceReferenceQuery,
        private readonly WorkCalendarService $workCalendar,
    ) {}

    public function readyQueueCount(): int
    {
        return $this->caseQueueReadModel->queueCount(OperationQueue::ActionRequired);
    }

    public function latestServiceReference(): ?LatestServiceReference
    {
        return $this->latestServiceReferenceQuery->latest();
    }

    public function isRecipientEligible(User $user, ?Carbon $at = null): bool
    {
        $at ??= now();

        if ($this->workCalendar->scheduleFor($user, $at) === null) {
            return false;
        }

        return $this->workCalendar->isEligibleForAssignment($user, $at);
    }
}
