<?php

namespace App\Http\Requests\Inventory;

use App\Support\HardwareFulfilment\HardwareFulfilmentAccess;
use Illuminate\Foundation\Http\FormRequest;

class AssignHardwareFulfilmentAwbRequest extends FormRequest
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
            'awb' => ['prohibited'],
            'courier_id' => ['prohibited'],
            'courier_name' => ['prohibited'],
            'branch' => ['prohibited'],
            'pickup' => ['prohibited'],
            'parcel' => ['prohibited'],
            'provider_order_id' => ['prohibited'],
            'external_order_id' => ['prohibited'],
            'channel_id' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'awb.prohibited' => 'AWB is assigned by Shiprocket and cannot be entered.',
            'courier_id.prohibited' => 'Courier is taken from the selected Shiprocket option and cannot be entered.',
        ];
    }
}
