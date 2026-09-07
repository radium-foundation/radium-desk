<?php

namespace App\Http\Requests\Inventory;

use App\Support\HardwareFulfilment\HardwareFulfilmentAccess;
use Illuminate\Foundation\Http\FormRequest;

class CreateHardwareFulfilmentShipmentRequest extends FormRequest
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
            'branch' => ['prohibited'],
            'fulfilment_branch_id' => ['prohibited'],
            'claimed_branch' => ['prohibited'],
            'pickup' => ['prohibited'],
            'pickup_location' => ['prohibited'],
            'parcel' => ['prohibited'],
            'weight' => ['prohibited'],
            'length' => ['prohibited'],
            'breadth' => ['prohibited'],
            'height' => ['prohibited'],
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
            'branch.prohibited' => 'Pickup branch is derived from allocated stock and cannot be chosen.',
            'fulfilment_branch_id.prohibited' => 'Pickup branch is derived from allocated stock and cannot be chosen.',
            'claimed_branch.prohibited' => 'Pickup branch is derived from allocated stock and cannot be chosen.',
            'pickup.prohibited' => 'Pickup location is derived from the physical stock branch and cannot be chosen.',
            'pickup_location.prohibited' => 'Pickup location is derived from the physical stock branch and cannot be chosen.',
            'parcel.prohibited' => 'Parcel dimensions are taken from persisted order data and cannot be entered.',
            'weight.prohibited' => 'Parcel dimensions are taken from persisted order data and cannot be entered.',
            'length.prohibited' => 'Parcel dimensions are taken from persisted order data and cannot be entered.',
            'breadth.prohibited' => 'Parcel dimensions are taken from persisted order data and cannot be entered.',
            'height.prohibited' => 'Parcel dimensions are taken from persisted order data and cannot be entered.',
            'provider_order_id.prohibited' => 'Provider order id is assigned by Shiprocket and cannot be entered.',
            'external_order_id.prohibited' => 'Provider order id is assigned by Shiprocket and cannot be entered.',
            'channel_id.prohibited' => 'Shiprocket channel id cannot be supplied by the operator.',
        ];
    }
}
