<?php

namespace App\Data\Retention;

use Illuminate\Support\Carbon;

readonly class RetentionEmailScheduleResult
{
    /**
     * @param  array<string, int>  $unknownCustomerAgeBuckets
     * @param  array<string, int>  $noiseCandidatesByIgnoreReason
     * @param  array<string, int>  $noiseAgeBuckets
     * @param  list<string>  $anomalies
     */
    public function __construct(
        public string $mode,
        public Carbon $startedAt,
        public Carbon $finishedAt,
        public bool $success,
        public bool $aborted,
        public ?string $abortReason,
        public int $unknownCustomerDays,
        public int $ignoredEmailDays,
        public int $unknownCustomerCandidates,
        public int $noiseCandidates,
        public int $needsReviewBacklog,
        public int $orderLinkedCount,
        public array $unknownCustomerAgeBuckets,
        public array $noiseCandidatesByIgnoreReason,
        public array $noiseAgeBuckets,
        public ?string $unknownCustomerOldestReceivedAt,
        public ?string $unknownCustomerNewestReceivedAt,
        public ?string $noiseOldestReceivedAt,
        public ?string $noiseNewestReceivedAt,
        public int $unknownCustomerEstimatedBytes,
        public int $noiseEstimatedBytes,
        public ?string $unknownCustomerManifestPath,
        public ?string $unknownCustomerManifestSha256,
        public ?string $noiseManifestPath,
        public ?string $noiseManifestSha256,
        public int $unknownCustomerSafetyFailures,
        public int $noiseSafetyFailures,
        public int $unknownCustomerDeleted,
        public int $noiseDeleted,
        public int $unknownCustomerBatches,
        public int $noiseBatches,
        public int $postUnknownCustomerCandidates,
        public int $postNoiseCandidates,
        public ?string $auditLogPath,
        public array $anomalies,
    ) {}
}
