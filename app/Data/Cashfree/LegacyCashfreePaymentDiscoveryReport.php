<?php

namespace App\Data\Cashfree;

use App\Enums\LegacyCashfreePaymentDiscoveryClassification;

final class LegacyCashfreePaymentDiscoveryReport
{
    /**
     * @param  list<LegacyCashfreePaymentDiscoveryRow>  $rows
     */
    public function __construct(
        public readonly bool $dryRun,
        public readonly int $candidateLimit,
        public readonly bool $legacyOnly,
        public readonly array $rows,
    ) {}

    /**
     * @return array<string, int>
     */
    public function totalsByClassification(): array
    {
        $totals = [];

        foreach (LegacyCashfreePaymentDiscoveryClassification::cases() as $case) {
            $totals[$case->value] = 0;
        }

        foreach ($this->rows as $row) {
            $totals[$row->classification->value]++;
        }

        return $totals;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'dry_run' => $this->dryRun,
            'candidate_limit' => $this->candidateLimit,
            'legacy_only' => $this->legacyOnly,
            'total_candidates' => count($this->rows),
            'totals' => $this->totalsByClassification(),
            'rows' => array_map(
                static fn (LegacyCashfreePaymentDiscoveryRow $row): array => $row->toArray(),
                $this->rows,
            ),
        ];
    }
}
