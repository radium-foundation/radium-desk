<?php

namespace App\Http\Requests;

use App\Support\Workforce\AttendanceManagementAccess;
use Illuminate\Foundation\Http\FormRequest;

class ReviseEmployeeSalaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return AttendanceManagementAccess::allows($this->user());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'monthly_salary' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'effective_from' => ['required', 'date'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_active' => $this->boolean('is_active')]);
    }
}
