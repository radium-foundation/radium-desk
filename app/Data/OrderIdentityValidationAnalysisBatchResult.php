<?php

namespace App\Data;

readonly class OrderIdentityValidationAnalysisBatchResult
{
    /**
     * @param  list<OrderIdentityValidationAnalysis>  $failures
     * @param  array<string, int>  $failuresByProduct
     * @param  array<string, int>  $failuresByValidatorRule
     * @param  array<string, int>  $failuresByGroup
     * @param  array<string, int>  $topInvalidSerialPatterns
     */
    public function __construct(
        public int $ordersScanned,
        public int $failureCount,
        public array $failures,
        public array $failuresByProduct,
        public array $failuresByValidatorRule,
        public array $failuresByGroup,
        public array $topInvalidSerialPatterns,
        public float $elapsedSeconds,
    ) {}
}
