<?php

namespace App\Http\Requests\Inventory;

use App\Support\HardwareFulfilment\HardwareFulfilmentAccess;
use Illuminate\Foundation\Http\FormRequest;

class CorrectHardwareFulfilmentShippingCountryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return HardwareFulfilmentAccess::allowsCountryCorrection($this->user());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'country' => ['required', 'string', 'max:64'],
            'parcel' => ['prohibited'],
            'weight' => ['prohibited'],
            'length' => ['prohibited'],
            'breadth' => ['prohibited'],
            'height' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'country.required' => 'Country must be supplied explicitly. It is not inferred.',
            'parcel.prohibited' => 'Parcel cannot be supplied on country correction.',
            'weight.prohibited' => 'Parcel cannot be supplied on country correction.',
            'length.prohibited' => 'Parcel cannot be supplied on country correction.',
            'breadth.prohibited' => 'Parcel cannot be supplied on country correction.',
            'height.prohibited' => 'Parcel cannot be supplied on country correction.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('country')) {
            $this->merge([
                'country' => trim((string) $this->input('country')),
            ]);
        }
    }
}
