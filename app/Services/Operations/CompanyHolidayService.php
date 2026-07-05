<?php

namespace App\Services\Operations;

use App\Enums\CompanyHolidayType;
use App\Models\CompanyHoliday;
use Illuminate\Support\Carbon;

class CompanyHolidayService
{
    /**
     * @param  array{holiday_date: string, name: string, type: string}  $data
     */
    public function create(array $data): CompanyHoliday
    {
        return CompanyHoliday::query()->create([
            'holiday_date' => $data['holiday_date'],
            'name' => $data['name'],
            'type' => CompanyHolidayType::from($data['type']),
        ]);
    }

    public function delete(CompanyHoliday $holiday): void
    {
        $holiday->delete();
    }

    /**
     * @return list<CompanyHoliday>
     */
    public function upcoming(int $limit = 20): array
    {
        return CompanyHoliday::query()
            ->whereDate('holiday_date', '>=', now()->toDateString())
            ->orderBy('holiday_date')
            ->limit($limit)
            ->get()
            ->all();
    }

    public function isHoliday(?Carbon $at = null): bool
    {
        $at ??= now();

        return CompanyHoliday::query()
            ->whereDate('holiday_date', $at->toDateString())
            ->exists();
    }
}
