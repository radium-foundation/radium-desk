<?php

namespace App\Support\Assignment\Contracts;

use App\Data\Assignment\SupportAssignmentRequest;
use App\Models\User;
use Illuminate\Support\Collection;

interface SupportAssignmentStrategy
{
    public function type(): string;

    /**
     * @param  Collection<int, User>  $candidates
     */
    public function selectAssignee(Collection $candidates, SupportAssignmentRequest $request): ?User;
}
