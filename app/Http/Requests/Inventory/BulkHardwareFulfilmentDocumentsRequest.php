<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class BulkHardwareFulfilmentDocumentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'fulfilment_ids' => ['required', 'array', 'min:1'],
            'fulfilment_ids.*' => ['required', 'integer', 'min:1'],
        ];
    }
}
