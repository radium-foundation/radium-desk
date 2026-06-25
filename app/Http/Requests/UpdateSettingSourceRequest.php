<?php

namespace App\Http\Requests;

use App\Models\SettingProduct;
use App\Models\SettingSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSettingSourceRequest extends FormRequest
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
        /** @var SettingSource $source */
        $source = $this->route('source');

        return [
            'label' => ['required', 'string', 'max:255'],
            'icon' => ['required', 'string', 'max:100', 'regex:/^bi-[a-z0-9-]+$/'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
        ];
    }
}
