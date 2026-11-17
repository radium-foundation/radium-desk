<?php

namespace App\Services\HardwareFulfilment;

/**
 * Fail-closed execution token for P-17-43 gap recovery writes only.
 */
final class RbpHardwareFulfilmentGapRecoveryExecution
{
    public const PURPOSE = 'p17-43-rbp-hardware-fulfilment-gap-recovery';

    private function __construct(
        private readonly string $purpose,
        private readonly string $sourceId,
    ) {}

    public static function forAllowlistedSource(string $sourceId): self
    {
        return new self(self::PURPOSE, strtoupper(trim($sourceId)));
    }

    public function isValid(): bool
    {
        if ($this->purpose !== self::PURPOSE || $this->sourceId === '') {
            return false;
        }

        $allowlist = config('hardware_fulfilment.rbp_gap_recovery.allowlist', []);

        return is_array($allowlist)
            && in_array($this->sourceId, array_map(static fn (mixed $id): string => strtoupper(trim((string) $id)), $allowlist), true);
    }

    public function sourceId(): string
    {
        return $this->sourceId;
    }
}
