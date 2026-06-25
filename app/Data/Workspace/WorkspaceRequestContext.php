<?php

namespace App\Data\Workspace;

use App\Enums\WorkspaceContext;

readonly class WorkspaceRequestContext
{
    public function __construct(
        public WorkspaceContext $context,
        public ?int $incidentId = null,
        public ?int $orderId = null,
        public ?string $sourcePage = null,
    ) {}
}
