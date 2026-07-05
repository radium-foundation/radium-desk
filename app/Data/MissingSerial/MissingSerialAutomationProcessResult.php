<?php

namespace App\Data\MissingSerial;

readonly class MissingSerialAutomationProcessResult
{
    public function __construct(
        public int $scanned,
        public int $sent,
        public int $reminded,
        public int $escalated,
        public int $skipped,
        public int $failed,
    ) {}
}
