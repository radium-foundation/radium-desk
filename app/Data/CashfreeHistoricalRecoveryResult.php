<?php

namespace App\Data;

readonly class CashfreeHistoricalRecoveryResult
{
    public function __construct(
        public int $found,
        public int $recoverable,
        public int $alreadyExists,
        public int $unsafe,
        public int $recovered = 0,
        public int $stillFailed = 0,
    ) {}
}
