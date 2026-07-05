<?php

namespace App\Http\Requests;

use App\Enums\CompanyHolidayType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCompanyHolidayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', \App\Models\CompanyHoliday::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'holiday_date' => ['required', 'date', 'unique:company_holidays,holiday_date'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(CompanyHolidayType::class)],
        ];
    }
}
