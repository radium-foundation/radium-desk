<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\StatutoryInvoice\ServiceStatutoryGstMismatchStatus;
use App\Mail\NotificationMail;
use App\Models\ServiceStatutoryGstMismatchException;
use App\Services\Notifications\NotificationMailSender;
use App\Support\AppDateFormatter;
use App\Support\Finance\GstStateCodes;
use Illuminate\Support\Facades\DB;

final class ServiceStatutoryGstMismatchCustomerEmailService
{
    public function __construct(
        private readonly NotificationMailSender $mailSender,
    ) {}

    public function sendIfNeeded(ServiceStatutoryGstMismatchException $exception): void
    {
        if (! (bool) config('service_statutory_invoice.gst_mismatch.customer_email_enabled', true)) {
            return;
        }

        if ($exception->customer_email_sent_at !== null) {
            return;
        }

        $email = trim((string) ($exception->customer_email ?? ''));
        if ($email === '' || ! $this->mailSender->isEnabled()) {
            return;
        }

        DB::transaction(function () use ($exception, $email): void {
            $locked = ServiceStatutoryGstMismatchException::query()
                ->whereKey($exception->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->customer_email_sent_at !== null) {
                return;
            }

            $commerce = $locked->commerceOrder;
            $orderId = $commerce?->source_id ?? $locked->supportOrder?->order_id ?? 'your order';
            $gstinState = GstStateCodes::nameForCode(BuyerGstin::stateCode($locked->original_buyer_gstin));
            $billingState = $locked->original_billing_state;
            $deadline = AppDateFormatter::datetime($locked->response_deadline_at);

            $this->mailSender->send(
                $email,
                new NotificationMail(
                    mailSubject: 'Payment received — GST details need verification for '.$orderId,
                    viewName: 'emails.statutory-gst-mismatch-verification',
                    variables: [
                        'orderId' => $orderId,
                        'customerName' => $commerce?->customer_name,
                        'gstin' => $locked->original_buyer_gstin,
                        'gstinState' => $gstinState,
                        'billingState' => $billingState,
                        'validationReason' => $locked->validation_reason,
                        'responseDeadline' => $deadline,
                    ],
                ),
            );

            $locked->update([
                'customer_email_sent_at' => now(),
                'status' => $locked->status === ServiceStatutoryGstMismatchStatus::GstMismatch
                    ? ServiceStatutoryGstMismatchStatus::AwaitingCustomer
                    : $locked->status,
            ]);
        });
    }
}
