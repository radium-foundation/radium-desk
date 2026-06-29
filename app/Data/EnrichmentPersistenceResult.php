<?php

namespace App\Data;

readonly class EnrichmentPersistenceResult
{
    /**
     * @param  list<string>  $fieldsApplied
     */
    public function __construct(
        public bool $updated,
        public array $fieldsApplied,
        public bool $serialApplied,
        public bool $deviceModelApplied,
        public bool $warrantyApplied,
        public bool $activationYearApplied,
        public bool $amcApplied,
    ) {}
}
