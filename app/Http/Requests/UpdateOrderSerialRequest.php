<?php

namespace App\Http\Requests;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;

class UpdateOrderSerialRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Order $order */
        $order = $this->route('order');

        if ($this->user()?->can('assignSerial', $order)) {
            return true;
        }

        return $order->isSerialLocked()
            && ($this->user()?->can('incidents.update') ?? false);
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('serial_number')) {
            $this->merge([
                'serial_number' => strtoupper(trim($this->string('serial_number')->toString())),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'serial_number' => ['required', 'string', 'max:100'],
            'incident_id' => ['nullable', 'integer', 'exists:incidents,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'serial_number' => 'serial number',
        ];
    }
}
