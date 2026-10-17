<?php

namespace App\Services\ReturnExchange;

use App\Enums\RefundDifferenceReason;
use App\Enums\ReturnExchangeCaseStatus;
use App\Models\RefundRequest;
use App\Models\ReturnExchangeCase;
use App\Models\User;
use App\Services\Refunds\HardwareOrderRefundCaseGate;
use App\Services\RefundRequestService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReturnExchangeCaseOnlineRefundSubmitService
{
    public function __construct(
        private readonly ReturnExchangeCaseOnlineHardwareContext $hardwareContext,
        private readonly ReturnExchangeCaseRecordedTaxOutcomeVerifier $taxOutcome,
        private readonly ReturnExchangeCaseReturnedHardwareEligibleRefundService $eligibleAmounts,
        private readonly ReturnExchangeCaseOnlineRefundTenderEligibilityGuard $tenderGuard,
        private readonly RefundRequestService $refundRequests,
    ) {}

    /**
     * @param  array{reason?: string|null, requester_remarks?: string|null}  $payload
     */
    public function submit(User $actor, ReturnExchangeCase $case, array $payload, Request $httpRequest): RefundRequest
    {
        try {
            return DB::transaction(function () use ($actor, $case, $payload, $httpRequest): RefundRequest {
                $lockedCase = ReturnExchangeCase::query()
                    ->whereKey($case->id)
                    ->lockForUpdate()
                    ->with(['lines', 'receipts'])
                    ->firstOrFail();

                if ($lockedCase->refund_request_id !== null) {
                    throw ValidationException::withMessages([
                        'refund_request' => 'This return case already has a linked refund request.',
                    ]);
                }

                if ($lockedCase->status !== ReturnExchangeCaseStatus::Inspected) {
                    throw ValidationException::withMessages([
                        'case' => 'Goods must be received and inspected before submitting a refund request.',
                    ]);
                }

                $context = $this->hardwareContext->resolve($lockedCase);
                $this->assertFullReturn($lockedCase, $context);
                $this->assertOrderPaymentCorrelation($context->order);

                $tax = $this->taxOutcome->verify($lockedCase);
                if (! $tax->verified || $tax->invoice === null || $tax->evidenceType === null) {
                    throw ValidationException::withMessages([
                        'tax_evidence' => $tax->blockerMessage ?? 'Tax evidence could not be verified.',
                    ]);
                }

                $eligible = $this->eligibleAmounts->resolve($lockedCase, $context, $tax->invoice);
                $this->tenderGuard->assertMaySubmit($context, $eligible->amount);

                $reason = trim((string) ($payload['reason'] ?? 'Online hardware return — case '.$lockedCase->case_no));
                if ($reason === '') {
                    $reason = 'Online hardware return — case '.$lockedCase->case_no;
                }

                $refundPayload = [
                    'order_id' => $context->order->id,
                    'reason' => $reason,
                    'remarks' => $payload['requester_remarks'] ?? null,
                    'deduction_profile_key' => 'full_refund',
                    'amount' => $eligible->amount,
                    'hardware_refund_gate_bypass' => HardwareOrderRefundCaseGate::BYPASS_RETURN_EXCHANGE_CASE_ONLINE,
                ];

                if ($eligible->isPartialRelativeToOrderPayment) {
                    $refundPayload['partial_difference_reason'] = RefundDifferenceReason::PartialRefund->value;
                    $refundPayload['partial_difference_notes'] = 'Return case covers returned hardware only (item-level cap).';
                }

                $refund = $this->refundRequests->create($actor, $refundPayload, $httpRequest);

                $lockedCase->update([
                    'refund_request_id' => $refund->id,
                    'recorded_tax_statutory_invoice_id' => $tax->invoice->id,
                    'recorded_tax_outcome_type' => $tax->evidenceType->value,
                    'order_id' => $lockedCase->order_id ?? $context->order->id,
                ]);

                return $refund->fresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'refund_request' => 'A refund request is already linked to this return case.',
            ]);
        }
    }

    private function assertOrderPaymentCorrelation(\App\Models\Order $order): void
    {
        $paid = round((float) ($order->payment_amount ?? 0), 2);
        if ($paid < 0.01) {
            return;
        }

        $hasGateway = filled($order->cashfree_payment_id) || filled($order->gateway_payment_id);
        if (! $hasGateway) {
            throw ValidationException::withMessages([
                'order' => 'Order payment correlation (gateway payment id) is missing.',
            ]);
        }
    }

    private function assertFullReturn(ReturnExchangeCase $case, \App\Services\ReturnExchange\Data\ResolvedOnlineHardwareReturnContext $context): void
    {
        foreach ($case->lines as $line) {
            $receipt = $case->receipts->firstWhere('return_exchange_case_line_id', $line->id);
            if ($receipt === null || $receipt->inspected_at === null) {
                throw ValidationException::withMessages([
                    'case' => 'All return lines must be received and inspected before refund request.',
                ]);
            }
        }

        $fulfilment = $context->fulfilment;
        if ($fulfilment === null) {
            if ($case->lines->sum('qty') !== 1) {
                throw ValidationException::withMessages([
                    'lines' => 'Partial returns are not supported; the case must cover the full hardware return.',
                ]);
            }

            return;
        }

        $fulfilment->loadMissing('serials');
        $expectedUnits = $fulfilment->serials->isEmpty()
            ? 1
            : $fulfilment->serials->count();

        $caseQty = (int) $case->lines->sum('qty');
        if ($caseQty !== $expectedUnits) {
            throw ValidationException::withMessages([
                'lines' => 'Partial fulfilment returns are not supported (expected '.$expectedUnits.' unit(s), case has '.$caseQty.').',
            ]);
        }

        foreach ($fulfilment->serials as $fulfilmentSerial) {
            if ($fulfilmentSerial->inventory_serial_id === null) {
                continue;
            }

            $matched = $case->receipts
                ->where('inspected_at', '!=', null)
                ->contains(fn ($receipt): bool => (int) $receipt->inventory_serial_id === (int) $fulfilmentSerial->inventory_serial_id);

            if (! $matched) {
                throw ValidationException::withMessages([
                    'serials' => 'Allocated fulfilment serial #'.$fulfilmentSerial->inventory_serial_id.' must be received and inspected with validated inventory linkage.',
                ]);
            }
        }
    }
}
