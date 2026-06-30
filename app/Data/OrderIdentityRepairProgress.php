<?php

namespace App\Data;

readonly class OrderIdentityRepairProgress
{
    public function __construct(
        public int $processed,
        public int $batchTotal,
        public int $repaired,
        public int $alreadyValid,
        public int $failed,
        public int $rateLimited,
        public int $remaining,
    ) {}
}
