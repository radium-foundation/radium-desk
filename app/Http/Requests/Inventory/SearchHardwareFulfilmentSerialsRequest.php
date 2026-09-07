<?php

namespace App\Http\Requests\Inventory;

use App\Support\HardwareFulfilment\HardwareFulfilmentAccess;
use Illuminate\Foundation\Http\FormRequest;

class SearchHardwareFulfilmentSerialsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return HardwareFulfilmentAccess::allows($this->user());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'commerce_order_item_id' => ['required', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:80'],
            'branch' => ['nullable', 'string', 'max:40'],
        ];
    }
}
