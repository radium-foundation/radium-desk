<?php

namespace App\Data;

use App\Enums\AutomationPolicyActionType;

readonly class AutomationPolicyAction
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        public AutomationPolicyActionType $type,
        public string $key,
        public array $config = [],
    ) {}
}
