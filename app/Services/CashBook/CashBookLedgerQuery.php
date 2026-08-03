<?php

namespace App\Services\CashBook;

use App\Enums\CashBookEntryType;
use App\Enums\CashBookExpenseCategory;
use App\Enums\CashBookIncomeSource;
use App\Models\CashBookEntry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class CashBookLedgerQuery
{
    /**
     * @param  array{
     *     q?: string|null,
     *     period?: string|null,
     *     type?: string|null,
     *     date_from?: string|null,
     *     date_to?: string|null
     * }  $filters
     */
    public function paginate(array $filters, int $perPage = 50): LengthAwarePaginator
    {
        return $this->query($filters)
            ->with('creator')
            ->latest('created_at')
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @param  array{
     *     q?: string|null,
     *     period?: string|null,
     *     type?: string|null,
     *     date_from?: string|null,
     *     date_to?: string|null
     * }  $filters
     * @return Builder<CashBookEntry>
     */
    public function query(array $filters): Builder
    {
        $query = CashBookEntry::query();

        $type = trim((string) ($filters['type'] ?? ''));
        if ($type === CashBookEntryType::Income->value || $type === CashBookEntryType::Expense->value) {
            $query->where('type', $type);
        }

        $this->applyPeriod($query, $filters);

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $this->applySearch($query, $search);
        }

        return $query;
    }

    /**
     * @param  Builder<CashBookEntry>  $query
     * @param  array{
     *     period?: string|null,
     *     date_from?: string|null,
     *     date_to?: string|null
     * }  $filters
     */
    private function applyPeriod(Builder $query, array $filters): void
    {
        $period = trim((string) ($filters['period'] ?? 'today'));
        $today = now()->startOfDay();

        match ($period) {
            'yesterday' => $query->whereDate('entry_date', $today->copy()->subDay()->toDateString()),
            'this_week' => $query->whereBetween('entry_date', [
                $today->copy()->startOfWeek()->toDateString(),
                $today->copy()->endOfWeek()->toDateString(),
            ]),
            'this_month' => $query->whereBetween('entry_date', [
                $today->copy()->startOfMonth()->toDateString(),
                $today->copy()->endOfMonth()->toDateString(),
            ]),
            'custom' => $this->applyCustomRange($query, $filters),
            'all' => null,
            default => $query->whereDate('entry_date', $today->toDateString()),
        };
    }

    /**
     * @param  Builder<CashBookEntry>  $query
     * @param  array{date_from?: string|null, date_to?: string|null}  $filters
     */
    private function applyCustomRange(Builder $query, array $filters): void
    {
        $from = trim((string) ($filters['date_from'] ?? ''));
        $to = trim((string) ($filters['date_to'] ?? ''));

        if ($from !== '') {
            try {
                $query->whereDate('entry_date', '>=', Carbon::parse($from)->toDateString());
            } catch (\Throwable) {
                // Ignore invalid custom from-date.
            }
        }

        if ($to !== '') {
            try {
                $query->whereDate('entry_date', '<=', Carbon::parse($to)->toDateString());
            } catch (\Throwable) {
                // Ignore invalid custom to-date.
            }
        }
    }

    /**
     * @param  Builder<CashBookEntry>  $query
     */
    private function applySearch(Builder $query, string $search): void
    {
        $like = '%'.$search.'%';
        $categoryValues = $this->categoryValuesMatching($search);

        $query->where(function (Builder $inner) use ($like, $search, $categoryValues): void {
            $inner->where('remark', 'like', $like)
                ->orWhere('person', 'like', $like)
                ->orWhere('entry_no', 'like', $like)
                ->orWhere('category', 'like', $like);

            if (is_numeric(str_replace([',', ' '], '', $search))) {
                $amount = (float) str_replace([',', ' '], '', $search);
                $inner->orWhere('amount', $amount);
            }

            if ($categoryValues !== []) {
                $inner->orWhereIn('category', $categoryValues);
            }
        });
    }

    /**
     * @return list<string>
     */
    private function categoryValuesMatching(string $search): array
    {
        $needle = mb_strtolower($search);
        $values = [];

        foreach (CashBookIncomeSource::cases() as $source) {
            if (str_contains(mb_strtolower($source->label()), $needle)
                || str_contains($source->value, $needle)) {
                $values[] = $source->value;
            }
        }

        foreach (CashBookExpenseCategory::cases() as $category) {
            if (str_contains(mb_strtolower($category->label()), $needle)
                || str_contains($category->value, $needle)) {
                $values[] = $category->value;
            }
        }

        return array_values(array_unique($values));
    }
}
