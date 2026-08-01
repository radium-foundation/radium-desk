<?php

namespace App\Http\Requests;

use App\Support\Workforce\AttendanceManagementAccess;
use Illuminate\Foundation\Http\FormRequest;

class FinalizePayrollMonthRequest extends FormRequest
{
    public function authorize(): bool
    {
        return AttendanceManagementAccess::allowsPayroll($this->user());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'month' => ['required', 'date_format:Y-m'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
