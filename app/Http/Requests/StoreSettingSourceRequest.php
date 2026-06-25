<?php

namespace App\Http\Requests;

use App\Models\SettingProduct;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSettingSourceRequest extends FormRequest
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
            'key' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/', Rule::unique('setting_sources', 'key')],
            'label' => ['required', 'string', 'max:255'],
            'icon' => ['required', 'string', 'max:100', 'regex:/^bi-[a-z0-9-]+$/'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
        ];
    }
}
