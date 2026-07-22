<?php

namespace App\Support\Repair\Contracts;

use App\Support\Repair\Data\RepairBatchOptions;
use App\Support\Repair\Data\RepairCandidate;
use App\Support\Repair\Data\RepairClassification;
use Generator;

interface CandidateResolverInterface
{
    public function count(RepairBatchOptions $options): int;

    /**
     * @return Generator<int, RepairCandidate>
     */
    public function iterate(RepairBatchOptions $options): Generator;

    public function classify(RepairCandidate $candidate, RepairBatchOptions $options): RepairClassification;
}
