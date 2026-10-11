<?php

namespace App\Data\Cashfree;

final readonly class LegacySafeCashfreePaymentLinkReport
{
    /**
     * @param  list<LegacySafeCashfreePaymentLinkRowResult>  $rows
     */
    public function __construct(
        public bool $dryRun,
        public string $sourceReportPath,
        public int $eligibleCount,
        public array $rows,
    ) {}

    /**
     * @return array<string, int>
     */
    public function totalsByResult(): array
    {
        $totals = [];

        foreach ($this->rows as $row) {
            $key = $row->result->value;
            $totals[$key] = ($totals[$key] ?? 0) + 1;
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
            'source_report_path' => $this->sourceReportPath,
            'eligible_count' => $this->eligibleCount,
            'totals' => $this->totalsByResult(),
            'rows' => array_map(static fn (LegacySafeCashfreePaymentLinkRowResult $row): array => $row->toArray(), $this->rows),
        ];
    }
}
