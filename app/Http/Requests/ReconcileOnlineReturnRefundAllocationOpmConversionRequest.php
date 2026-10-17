<?php

namespace App\Http\Requests;

use App\Models\OnlineReturnRefundAllocationOpmConversion;
use Illuminate\Foundation\Http\FormRequest;

class ReconcileOnlineReturnRefundAllocationOpmConversionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $conversion = $this->route('conversion');

        return $conversion instanceof OnlineReturnRefundAllocationOpmConversion
            && $this->user() !== null
            && $this->user()->can($this->reconciliationAbility(), $conversion);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'notes' => ['required', 'string', 'max:500'],
        ];
    }

    private function reconciliationAbility(): string
    {
        return match ($this->route()->getName()) {
            'online-return-refund-allocation-opm-conversions.reconcile-reservation' => 'reconcileReservation',
            'online-return-refund-allocation-opm-conversions.reconcile-finalization' => 'reconcileFinalization',
            default => 'reconcileReservation',
        };
    }
}
