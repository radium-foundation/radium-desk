<?php

namespace App\Http\Requests;

use App\Models\SettingProduct;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingsGeneralRequest extends FormRequest
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
            'company_name' => ['required', 'string', 'max:255'],
            'company_email' => ['required', 'string', 'email', 'max:255'],
            'timezone' => ['required', 'string', 'timezone:all'],
        ];
    }
}
