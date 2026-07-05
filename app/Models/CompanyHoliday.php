<?php

namespace App\Models;

use App\Enums\CompanyHolidayType;
use Illuminate\Database\Eloquent\Model;

class CompanyHoliday extends Model
{
    protected $fillable = [
        'holiday_date',
        'name',
        'type',
    ];

    protected function casts(): array
    {
        return [
            'holiday_date' => 'date',
            'type' => CompanyHolidayType::class,
        ];
    }
}
