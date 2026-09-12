<?php

namespace App\Support\Purchasing;

use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Indian financial year for Purchasing document numbering.
 *
 * FY series increments each April–March year:
 * FY 2026-27 → PO-07-xxx
 * FY 2027-28 → PO-08-xxx
 */
final class PurchasingFinancialYear
{
    private const SERIES_BASE_START_YEAR = 2019;

    public function __construct(
        public readonly int $startYear,
    ) {
        if ($this->startYear < 2000 || $this->startYear > 2100) {
            throw ValidationException::withMessages([
                'financial_year' => 'Purchasing financial year is outside the supported range.',
            ]);
        }
    }

    public static function containing(Carbon $at): self
    {
        $year = (int) $at->format('Y');
        $month = (int) $at->format('n');

        return new self($month >= 4 ? $year : $year - 1);
    }

    public function seriesCode(): string
    {
        return str_pad((string) ($this->startYear - self::SERIES_BASE_START_YEAR), 2, '0', STR_PAD_LEFT);
    }

    public function purchaseOrderPrefix(): string
    {
        return 'PO-'.$this->seriesCode().'-';
    }

    public function token(): string
    {
        return $this->startYear.'-'.($this->startYear + 1);
    }
}
