<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\StatutoryInvoice\ServiceStatutoryGstMismatchStatus;
use App\Models\CommerceOrder;
use App\Models\Incident;
use App\Models\Order;
use App\Models\ServiceStatutoryGstMismatchException;
use App\Models\User;
use App\Services\AuditLogService;
use App\Support\Finance\GstStateCodes;
use App\Support\StatutoryInvoice\StatutoryBillingStructured;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ServiceStatutoryGstMismatchExceptionService
{
    public function __construct(
        private readonly ServiceStatutoryGstMismatchDetector $detector,
        private readonly ServiceStatutoryGstMismatchCustomerEmailService $customerEmail,
        private readonly AuditLogService $auditLog,
    ) {}

    public function findForCommerceOrder(CommerceOrder $order): ?ServiceStatutoryGstMismatchException
    {
        return ServiceStatutoryGstMismatchException::query()
            ->where('commerce_order_id', $order->id)
            ->first();
    }

    public function findForSupportOrder(Order $order): ?ServiceStatutoryGstMismatchException
    {
        return ServiceStatutoryGstMismatchException::query()
            ->where('support_order_id', $order->id)
            ->first();
    }

    public function openFromMintFailure(Order $supportOrder, CommerceOrder $commerce, string $errorMessage): ?ServiceStatutoryGstMismatchException
    {
        if (! $this->detector->messageContainsGstMismatch($errorMessage)) {
            return null;
        }

        if (! (bool) config('service_statutory_invoice.gst_mismatch.enabled', true)) {
            return null;
        }

        if ($commerce->statutory_invoice_id !== null) {
            return null;
        }

        $existing = $this->findForCommerceOrder($commerce);
        if ($existing !== null) {
            return $existing;
        }

        $reason = $this->detector->detectForCommerceOrder($commerce)
            ?? ServiceStatutoryGstMismatchDetector::REASON_BUYER_PIN_GSTIN_STATE_MISMATCH;

        $structured = StatutoryBillingStructured::fromStored($commerce->billing_address_structured);
        $hours = max(1, (int) config('service_statutory_invoice.gst_mismatch.response_hours', 72));
        $deadline = now()->addHours($hours);

        $incidentId = Incident::query()
            ->where('order_id', $supportOrder->id)
            ->orderByDesc('id')
            ->value('id');

        $exception = ServiceStatutoryGstMismatchException::query()->create([
            'commerce_order_id' => $commerce->id,
            'support_order_id' => $supportOrder->id,
            'incident_id' => $incidentId,
            'status' => ServiceStatutoryGstMismatchStatus::GstMismatch,
            'validation_reason' => $reason,
            'original_buyer_gstin' => BuyerGstin::normalize($commerce->buyer_gstin),
            'original_billing_state' => $commerce->billing_state,
            'original_place_of_supply_state' => $commerce->place_of_supply_state,
            'original_billing_pincode' => is_array($structured) ? ($structured['pincode'] ?? null) : null,
            'original_billing_address_structured' => $commerce->billing_address_structured,
            'original_billing_address' => $commerce->billing_address,
            'customer_email' => $commerce->customer_email,
            'response_deadline_at' => $deadline,
        ]);

        $this->auditLog->log(
            null,
            'service_statutory_gst_mismatch.opened',
            $commerce,
            null,
            [
                'support_order_id' => $supportOrder->id,
                'validation_reason' => $reason,
                'response_deadline_at' => $deadline->toIso8601String(),
            ],
        );

        $this->customerEmail->sendIfNeeded($exception->fresh() ?? $exception);

        return $exception->fresh();
    }

    public function shouldSkipMintAttempt(
        ?ServiceStatutoryGstMismatchException $exception,
        ?CommerceOrder $commerce = null,
    ): bool {
        if ($exception === null) {
            return false;
        }

        if ($exception->status->isTerminal()) {
            return true;
        }

        if ($exception->status === ServiceStatutoryGstMismatchStatus::ReadyForB2b) {
            return false;
        }

        if ($commerce !== null && $this->detector->detectForCommerceOrder($commerce) === null) {
            return false;
        }

        return $exception->status->blocksMintRetry();
    }

    /**
     * @param  array{
     *     buyer_gstin?: string|null,
     *     billing_state?: string|null,
     *     place_of_supply_state?: string|null,
     *     billing_pincode?: string|null,
     *     billing_address_structured?: array<string, mixed>|null,
     * }  $correction
     */
    public function recordVerifiedCorrection(
        ServiceStatutoryGstMismatchException $exception,
        array $correction,
        User $actor,
    ): ServiceStatutoryGstMismatchException {
        if ($exception->status->isTerminal()) {
            throw ValidationException::withMessages([
                'gst_mismatch' => 'This GST mismatch exception is already resolved.',
            ]);
        }

        $buyerGstin = BuyerGstin::normalize($correction['buyer_gstin'] ?? null);
        if ($buyerGstin === null || ! BuyerGstin::isValid($buyerGstin)) {
            throw ValidationException::withMessages([
                'buyer_gstin' => 'A valid corrected GSTIN is required.',
            ]);
        }

        $billingState = trim((string) ($correction['billing_state'] ?? ''));
        if ($billingState === '' || GstStateCodes::codeForName($billingState) === null) {
            throw ValidationException::withMessages([
                'billing_state' => 'A valid corrected billing state is required.',
            ]);
        }

        $placeOfSupply = trim((string) ($correction['place_of_supply_state'] ?? $billingState));
        if (GstStateCodes::codeForName($placeOfSupply) === null) {
            throw ValidationException::withMessages([
                'place_of_supply_state' => 'A valid corrected place of supply state is required.',
            ]);
        }

        $gstinState = BuyerGstin::stateCode($buyerGstin);
        $billingCode = GstStateCodes::codeForName($billingState);
        if ($gstinState !== null && $billingCode !== null && $gstinState !== $billingCode) {
            throw ValidationException::withMessages([
                'buyer_gstin' => 'Corrected GSTIN state does not match corrected billing state.',
            ]);
        }

        $structured = $correction['billing_address_structured'] ?? $exception->original_billing_address_structured;
        if (! is_array($structured)) {
            $structured = [];
        }
        $structured['state'] = $billingState;
        if (isset($correction['billing_pincode'])) {
            $structured['pincode'] = $correction['billing_pincode'];
        }

        return DB::transaction(function () use ($exception, $correction, $actor, $buyerGstin, $billingState, $placeOfSupply, $structured): ServiceStatutoryGstMismatchException {
            $locked = ServiceStatutoryGstMismatchException::query()
                ->whereKey($exception->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status->isTerminal()) {
                throw ValidationException::withMessages([
                    'gst_mismatch' => 'This GST mismatch exception is already resolved.',
                ]);
            }

            $commerce = CommerceOrder::query()->whereKey($locked->commerce_order_id)->lockForUpdate()->firstOrFail();
            if ($commerce->statutory_invoice_id !== null) {
                throw ValidationException::withMessages([
                    'gst_mismatch' => 'A statutory invoice already exists for this order.',
                ]);
            }

            $locked->update([
                'corrected_buyer_gstin' => $buyerGstin,
                'corrected_billing_state' => $billingState,
                'corrected_place_of_supply_state' => $placeOfSupply,
                'corrected_billing_pincode' => $correction['billing_pincode'] ?? ($structured['pincode'] ?? null),
                'corrected_billing_address_structured' => $structured,
                'customer_response_at' => now(),
                'corrected_by_user_id' => $actor->id,
                'corrected_at' => now(),
                'status' => ServiceStatutoryGstMismatchStatus::ReadyForB2b,
            ]);

            $commerce->update([
                'buyer_gstin' => $buyerGstin,
                'billing_state' => $billingState,
                'place_of_supply_state' => $placeOfSupply,
                'billing_address_structured' => $structured,
            ]);

            $this->auditLog->log(
                $actor->id,
                'service_statutory_gst_mismatch.correction_recorded',
                $commerce,
                [
                    'original_buyer_gstin' => $locked->original_buyer_gstin,
                    'original_billing_state' => $locked->original_billing_state,
                ],
                [
                    'corrected_buyer_gstin' => $buyerGstin,
                    'corrected_billing_state' => $billingState,
                    'corrected_place_of_supply_state' => $placeOfSupply,
                    'actor_id' => $actor->id,
                ],
            );

            return $locked->fresh() ?? $locked;
        });
    }

    public function markB2bIssued(ServiceStatutoryGstMismatchException $exception, int $invoiceId): void
    {
        if ($exception->status === ServiceStatutoryGstMismatchStatus::B2bInvoiceIssued) {
            return;
        }

        $exception->update([
            'status' => ServiceStatutoryGstMismatchStatus::B2bInvoiceIssued,
            'resolution_classification' => 'b2b',
            'statutory_invoice_id' => $invoiceId,
            'resolved_at' => now(),
        ]);
    }

    public function markB2cIssued(ServiceStatutoryGstMismatchException $exception, int $invoiceId, string $fallbackReason): void
    {
        if ($exception->status === ServiceStatutoryGstMismatchStatus::B2cInvoiceIssued) {
            return;
        }

        $exception->update([
            'status' => ServiceStatutoryGstMismatchStatus::B2cInvoiceIssued,
            'resolution_classification' => 'b2c_72h_fallback',
            'fallback_reason' => $fallbackReason,
            'fallback_at' => now(),
            'statutory_invoice_id' => $invoiceId,
            'resolved_at' => now(),
        ]);
    }

    public function transitionToFallbackPending(ServiceStatutoryGstMismatchException $exception): ServiceStatutoryGstMismatchException
    {
        if ($exception->status->isTerminal()) {
            return $exception;
        }

        $exception->update([
            'status' => ServiceStatutoryGstMismatchStatus::B2cFallbackPending,
            'fallback_reason' => 'GST mismatch unresolved after 72-hour customer verification window.',
        ]);

        return $exception->fresh() ?? $exception;
    }

    public function processDueExceptions(): void
    {
        $this->sendPendingCustomerEmails();
        $this->processExpiredAwaitingCustomer();
        $this->processReadyForB2b();
        $this->processB2cFallbackPending();
    }

    private function sendPendingCustomerEmails(): void
    {
        ServiceStatutoryGstMismatchException::query()
            ->whereNull('customer_email_sent_at')
            ->whereIn('status', [
                ServiceStatutoryGstMismatchStatus::GstMismatch->value,
                ServiceStatutoryGstMismatchStatus::AwaitingCustomer->value,
            ])
            ->orderBy('id')
            ->each(function (ServiceStatutoryGstMismatchException $exception): void {
                $this->customerEmail->sendIfNeeded($exception);
            });
    }

    private function processExpiredAwaitingCustomer(): void
    {
        ServiceStatutoryGstMismatchException::query()
            ->where('response_deadline_at', '<=', now())
            ->whereIn('status', [
                ServiceStatutoryGstMismatchStatus::GstMismatch->value,
                ServiceStatutoryGstMismatchStatus::CustomerContacted->value,
                ServiceStatutoryGstMismatchStatus::AwaitingCustomer->value,
            ])
            ->orderBy('id')
            ->each(function (ServiceStatutoryGstMismatchException $exception): void {
                if ($exception->corrected_at !== null || $exception->statutory_invoice_id !== null) {
                    return;
                }

                app(ServiceStatutoryGstMismatchFallbackService::class)->queueB2cFallback($exception);
            });
    }

    private function processReadyForB2b(): void
    {
        ServiceStatutoryGstMismatchException::query()
            ->where('status', ServiceStatutoryGstMismatchStatus::ReadyForB2b)
            ->orderBy('id')
            ->each(function (ServiceStatutoryGstMismatchException $exception): void {
                app(ServiceStatutoryGstMismatchIssuanceService::class)->issueB2bIfReady($exception);
            });
    }

    private function processB2cFallbackPending(): void
    {
        ServiceStatutoryGstMismatchException::query()
            ->where('status', ServiceStatutoryGstMismatchStatus::B2cFallbackPending)
            ->orderBy('id')
            ->each(function (ServiceStatutoryGstMismatchException $exception): void {
                app(ServiceStatutoryGstMismatchFallbackService::class)->issueB2cFallback($exception);
            });
    }
}
