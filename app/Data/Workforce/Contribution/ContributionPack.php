<?php

namespace App\Data\Workforce\Contribution;

/**
 * Role threshold pack (DTO) — config-backed, not persisted.
 *
 * @phpstan-type SignalThresholdConfig array{
 *     enabled?: bool,
 *     reserved?: bool,
 *     normal?: int|float,
 *     high?: int|float
 * }
 */
readonly class ContributionPack
{
    /**
     * @param  array<string, SignalThresholdConfig>  $signalThresholds
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $strategy,
        public array $signalThresholds,
    ) {}

    public function isSignalEnabled(string $signalId): bool
    {
        $config = $this->signalThresholds[$signalId] ?? null;

        if ($config === null) {
            return false;
        }

        if (($config['reserved'] ?? false) === true) {
            return false;
        }

        return (bool) ($config['enabled'] ?? false);
    }
}
