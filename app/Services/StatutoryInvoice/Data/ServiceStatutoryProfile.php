<?php

namespace App\Services\StatutoryInvoice\Data;

/**
 * Owner-approved statutory classification for a configured service line.
 */
final class ServiceStatutoryProfile
{
    public function __construct(
        public readonly string $sac,
        public readonly string $isServc,
        public readonly string $uqc,
        public readonly string $serviceKey,
    ) {}
}
