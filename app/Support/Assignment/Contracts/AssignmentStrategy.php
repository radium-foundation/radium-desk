<?php

namespace App\Support\Assignment\Contracts;

use App\Data\Assignment\AssignmentRequest;
use App\Enums\Assignment\AssignmentQueue;
use App\Models\Incident;

interface AssignmentStrategy
{
    public function queue(): AssignmentQueue;

    public function assign(AssignmentRequest $request): Incident;
}
