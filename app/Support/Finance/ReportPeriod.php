<?php

namespace App\Support\Finance;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Inclusive calendar-date filter in the application timezone.
 */
final class ReportPeriod
{
    public function __construct(
        public readonly ?string $from,
        public readonly ?string $to,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            self::parse($request, 'date_from'),
            self::parse($request, 'date_to'),
        );
    }

    /**
     * @return array{date_from: string, date_to: string}
     */
    public function filters(): array
    {
        return [
            'date_from' => $this->from ?? '',
            'date_to' => $this->to ?? '',
        ];
    }

    public function apply(Builder $query, string $column): Builder
    {
        if ($this->from !== null) {
            $query->whereDate($column, '>=', $this->from);
        }

        if ($this->to !== null) {
            $query->whereDate($column, '<=', $this->to);
        }

        return $query;
    }

    private static function parse(Request $request, string $key): ?string
    {
        $raw = $request->string($key)->trim()->toString();
        if ($raw === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1) {
            return null;
        }

        return $raw;
    }
}
