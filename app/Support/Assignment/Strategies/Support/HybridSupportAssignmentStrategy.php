<?php

namespace App\Support\Assignment\Strategies\Support;

use App\Data\Assignment\SupportAssignmentRequest;
use App\Enums\Assignment\SupportAssignmentStrategyType;
use App\Models\User;
use App\Support\Assignment\Contracts\SupportAssignmentStrategy;
use Illuminate\Support\Collection;
use LogicException;

class HybridSupportAssignmentStrategy implements SupportAssignmentStrategy
{
    public function type(): string
    {
        return SupportAssignmentStrategyType::Hybrid->value;
    }

    /**
     * @param  Collection<int, User>  $candidates
     */
    public function selectAssignee(Collection $candidates, SupportAssignmentRequest $request): ?User
    {
        throw new LogicException('Hybrid support assignment is not enabled in production.');
    }
}
