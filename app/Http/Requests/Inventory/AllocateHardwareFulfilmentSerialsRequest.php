<?php

namespace App\Http\Requests\Inventory;

use App\Support\HardwareFulfilment\HardwareFulfilmentAccess;
use Illuminate\Foundation\Http\FormRequest;

class AllocateHardwareFulfilmentSerialsRequest extends FormRequest
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
            'serials' => ['required', 'array'],
            'serials.*' => ['array'],
            'serials.*.*' => ['string', 'max:80'],
            'claimed_branch' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'claimed_branch.prohibited' => 'Physical branch is derived from the selected serial stock location and cannot be chosen.',
        ];
    }
}
