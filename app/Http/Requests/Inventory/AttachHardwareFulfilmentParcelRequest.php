<?php

namespace App\Http\Requests\Inventory;

use App\Support\HardwareFulfilment\HardwareFulfilmentAccess;
use Illuminate\Foundation\Http\FormRequest;

class AttachHardwareFulfilmentParcelRequest extends FormRequest
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
            'parcel' => ['prohibited'],
            'weight' => ['prohibited'],
            'length' => ['prohibited'],
            'breadth' => ['prohibited'],
            'height' => ['prohibited'],
            'country' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'parcel.prohibited' => 'Parcel dimensions are copied from verified catalog packaging and cannot be entered.',
            'weight.prohibited' => 'Parcel dimensions are copied from verified catalog packaging and cannot be entered.',
            'length.prohibited' => 'Parcel dimensions are copied from verified catalog packaging and cannot be entered.',
            'breadth.prohibited' => 'Parcel dimensions are copied from verified catalog packaging and cannot be entered.',
            'height.prohibited' => 'Parcel dimensions are copied from verified catalog packaging and cannot be entered.',
            'country.prohibited' => 'Country cannot be supplied on parcel snapshot attach.',
        ];
    }
}
