<?php

namespace App\Http\Requests;

use App\Models\SettingProduct;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSettingProductRequest extends FormRequest
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
        /** @var SettingProduct $product */
        $product = $this->route('product');

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('setting_products', 'name')->ignore($product->id)],
            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
        ];
    }
}
