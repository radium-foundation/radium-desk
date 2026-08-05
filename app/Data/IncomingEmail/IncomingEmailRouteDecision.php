<?php

namespace App\Data\IncomingEmail;

use App\Enums\IncomingEmailClassification;
use App\Enums\IncomingEmailSmartRoute;

readonly class IncomingEmailRouteDecision
{
    public function __construct(
        public IncomingEmailSmartRoute $route,
        public string $reason,
        public IncomingEmailClassification $classification,
    ) {}
}
