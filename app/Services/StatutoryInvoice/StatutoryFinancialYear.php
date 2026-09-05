<?php

namespace App\Services\StatutoryInvoice;

use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Indian financial year for statutory invoice prefixes.
 *
 * Owner FY code = last digit of the start year + last digit of the end year.
 * FY 2026-27 → 67. FY 2027-28 → 78.
 */
final class StatutoryFinancialYear
{
    public function __construct(
        public readonly int $startYear,
    ) {
        if ($this->startYear < 2000 || $this->startYear > 2100) {
            throw ValidationException::withMessages([
                'financial_year' => 'Statutory financial year is outside the supported range.',
            ]);
        }
    }

    public static function containing(Carbon $at): self
    {
        $year = (int) $at->format('Y');
        $month = (int) $at->format('n');

        return new self($month >= 4 ? $year : $year - 1);
    }

    public static function fromToken(string $token): self
    {
        if (preg_match('/^(\d{4})-(\d{4})$/', trim($token), $matches) !== 1) {
            throw ValidationException::withMessages([
                'financial_year' => 'Statutory financial year token must be YYYY-YYYY.',
            ]);
        }

        $start = (int) $matches[1];
        $end = (int) $matches[2];
        if ($end !== $start + 1) {
            throw ValidationException::withMessages([
                'financial_year' => 'Statutory financial year end must be the following calendar year.',
            ]);
        }

        return new self($start);
    }

    public function code(): string
    {
        return sprintf('%d%d', $this->startYear % 10, ($this->startYear + 1) % 10);
    }

    public function token(): string
    {
        return $this->startYear.'-'.($this->startYear + 1);
    }
}
