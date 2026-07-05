<?php

namespace App\Data\MissingSerial;

use App\Enums\MissingSerialAutomationAction;

readonly class MissingSerialAutomationOrderResult
{
    /**
     * @param  array<string, string>  $channels
     */
    public function __construct(
        public int $orderId,
        public MissingSerialAutomationAction $action,
        public string $outcome,
        public ?string $message = null,
        public array $channels = [],
    ) {}
}
