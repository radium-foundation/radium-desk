<?php

namespace App\Http\Requests;

use App\Models\SettingProduct;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingsSlaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', SettingProduct::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'normal_warning_hours' => ['required', 'integer', 'min:1', 'max:720'],
            'normal_overdue_hours' => ['required', 'integer', 'min:1', 'max:720', 'gt:normal_warning_hours'],
            'priority_warning_hours' => ['required', 'integer', 'min:1', 'max:720'],
            'priority_overdue_hours' => ['required', 'integer', 'min:1', 'max:720', 'gt:priority_warning_hours'],
        ];
    }
}
