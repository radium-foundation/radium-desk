<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class BonvoiceClickToCallRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'order_id' => ['required_without:incident_id', 'integer', 'exists:orders,id'],
            'incident_id' => ['required_without:order_id', 'integer', 'exists:incidents,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->filled('order_id') && $this->filled('incident_id')) {
                $validator->errors()->add('order_id', 'Provide either order_id or incident_id, not both.');
                $validator->errors()->add('incident_id', 'Provide either order_id or incident_id, not both.');
            }
        });
    }
}
