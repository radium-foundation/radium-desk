<?php

namespace App\Services\Customer360;

use App\Models\Incident;
use App\Models\ServiceStatutoryGstMismatchException;
use App\Models\User;
use App\Services\StatutoryInvoice\BuyerGstin;
use App\Services\StatutoryInvoice\ServiceStatutoryGstMismatchExceptionService;
use App\Support\AppDateFormatter;
use App\Support\Finance\FinanceAccess;
use App\Support\Finance\GstStateCodes;

class Customer360GstMismatchExceptionPresenter
{
    public function __construct(
        private readonly ServiceStatutoryGstMismatchExceptionService $exceptions,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function forIncident(Incident $incident, ?User $user): ?array
    {
        if ($user === null || $user->cannot('view', $incident)) {
            return null;
        }

        $incident->loadMissing('order');
        $order = $incident->order;
        if ($order === null) {
            return null;
        }

        $exception = ServiceStatutoryGstMismatchException::query()
            ->where(function ($query) use ($incident, $order): void {
                $query->where('incident_id', $incident->id)
                    ->orWhere('support_order_id', $order->id);
            })
            ->orderByDesc('id')
            ->first();

        if ($exception === null) {
            return null;
        }

        return $this->present($incident, $exception, $user);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Incident $incident, ServiceStatutoryGstMismatchException $exception, User $user): array
    {
        $exception->loadMissing(['commerceOrder', 'statutoryInvoice']);
        $commerce = $exception->commerceOrder;
        $gstinStateCode = BuyerGstin::stateCode($exception->original_buyer_gstin);

        return [
            'id' => $exception->id,
            'status' => $exception->status->value,
            'status_label' => $exception->status->label(),
            'order_id' => $commerce?->source_id ?? $exception->supportOrder?->order_id,
            'payment_status' => $commerce?->payment_status,
            'validation_reason' => $exception->validation_reason,
            'original_buyer_gstin' => $exception->original_buyer_gstin,
            'original_gstin_state_code' => $gstinStateCode,
            'original_gstin_state_name' => GstStateCodes::nameForCode($gstinStateCode),
            'original_billing_state' => $exception->original_billing_state,
            'original_place_of_supply_state' => $exception->original_place_of_supply_state,
            'original_billing_pincode' => $exception->original_billing_pincode,
            'original_billing_address' => $exception->original_billing_address,
            'corrected_buyer_gstin' => $exception->corrected_buyer_gstin,
            'corrected_billing_state' => $exception->corrected_billing_state,
            'corrected_place_of_supply_state' => $exception->corrected_place_of_supply_state,
            'customer_email' => $exception->customer_email,
            'customer_email_sent_at_label' => AppDateFormatter::datetime($exception->customer_email_sent_at),
            'response_deadline_label' => AppDateFormatter::datetime($exception->response_deadline_at),
            'resolution_classification' => $exception->resolution_classification,
            'fallback_reason' => $exception->fallback_reason,
            'invoice_number' => $exception->statutoryInvoice?->invoice_number,
            'can_record_correction' => FinanceAccess::allowsInvoiceIssue($user)
                && ! $exception->status->isTerminal(),
            'correction_url' => route('dashboard.service-cases.customer-360.gst-mismatch.correction', [
                'incident' => $incident,
            ]),
        ];
    }
}
