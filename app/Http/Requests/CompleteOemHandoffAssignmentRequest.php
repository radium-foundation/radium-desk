<?php

namespace App\Http\Requests;

use App\Models\Incident;
use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;
class CompleteOemHandoffAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Incident $incident */
        $incident = $this->route('incident');
        $order = $incident->order;

        if ($order === null || $this->user()?->can('view', $incident) !== true) {
            return false;
        }

        if ($order->isTransactionLocked()) {
            return $this->user()?->can('orders.update') === true;
        }

        return $this->user()?->can('assignTransaction', $order) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'service_reference' => ['required', 'string', 'max:100'],
            'subscription_expiry_date' => ['required', 'date', 'before_or_equal:'.now()->addYears(30)->toDateString()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'service_reference' => 'service reference number',
            'subscription_expiry_date' => 'expiry date',
        ];
    }
}