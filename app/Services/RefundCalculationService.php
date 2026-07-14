<?php

namespace App\Services;

use App\Data\Refunds\RefundCalculationResult;
use App\Enums\RefundDeductionProfile;
use App\Enums\RefundDifferenceReason;
use App\Enums\RefundStatus;
use App\Models\Order;
use App\Models\RefundRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RefundCalculationService
{
    public function __construct(
        private readonly RefundProfileRegistry $profileRegistry,
    ) {}

    public function totalPaidAmount(Order $order): float
    {
        return round((float) ($order->payment_amount ?? 0), 2);
    }

    public function alreadyRefundedAmount(Order $order, ?RefundRequest $exclude = null): float
    {
        $query = $order->refundRequests()
            ->whereIn('status', array_map(
                fn (RefundStatus $status): string => $status->value,
                array_filter(
                    RefundStatus::cases(),
                    fn (RefundStatus $status): bool => $status->countsTowardAlreadyRefunded(),
                ),
            ));

        if ($exclude !== null && $exclude->exists) {
            $query->where('id', '!=', $exclude->id);
        }

        return round((float) $query->sum(DB::raw('COALESCE(refund_amount, amount)')), 2);
    }

    public function maximumRefundable(Order $order, ?RefundRequest $exclude = null): float
    {
        return max(0, round($this->totalPaidAmount($order) - $this->alreadyRefundedAmount($order, $exclude), 2));
    }

    /**
     * @param  array{
     *     deduction_profile_key?: string|null,
     *     cancellation_charges?: float|int|string|null,
     *     gst_on_cancellation?: float|int|string|null,
     *     other_deduction?: float|int|string|null,
     *     refund_amount?: float|int|string|null,
     *     partial_difference_reason?: string|null,
     *     partial_difference_notes?: string|null,
     * }  $input
     */
    public function calculate(Order $order, array $input = [], ?RefundRequest $exclude = null): RefundCalculationResult
    {
        $totalPaid = $this->totalPaidAmount($order);
        $alreadyRefunded = $this->alreadyRefundedAmount($order, $exclude);

        $explicitAmount = array_key_exists('refund_amount', $input) && $input['refund_amount'] !== null
            ? round((float) $input['refund_amount'], 2)
            : null;

        // Orders without recorded payment_amount remain supported for legacy create flows.
        if ($totalPaid <= 0 && $explicitAmount !== null) {
            $totalPaid = $explicitAmount + $alreadyRefunded;
        }

        $maximumRefundable = max(0, round($totalPaid - $alreadyRefunded, 2));

        $profileKey = $input['deduction_profile_key'] ?? RefundDeductionProfile::FullRefund->value;
        $profile = $this->profileRegistry->get((string) $profileKey);

        $cancellation = array_key_exists('cancellation_charges', $input) && $input['cancellation_charges'] !== null
            ? round((float) $input['cancellation_charges'], 2)
            : $profile->cancellationCharges;

        $gst = array_key_exists('gst_on_cancellation', $input) && $input['gst_on_cancellation'] !== null
            ? round((float) $input['gst_on_cancellation'], 2)
            : (float) ($profile->gstOnCancellation ?? 0);

        $other = array_key_exists('other_deduction', $input) && $input['other_deduction'] !== null
            ? round((float) $input['other_deduction'], 2)
            : $profile->otherDeduction;

        $totalDeduction = round($cancellation + $gst + $other, 2);

        $computedRefund = max(0, round($maximumRefundable - $totalDeduction, 2));

        $refundAmount = $explicitAmount ?? $computedRefund;

        return new RefundCalculationResult(
            totalPaidAmount: $totalPaid,
            alreadyRefundedAmount: $alreadyRefunded,
            maximumRefundable: $maximumRefundable,
            cancellationCharges: $cancellation,
            gstOnCancellation: $gst,
            otherDeduction: $other,
            totalDeduction: $totalDeduction,
            refundAmount: $refundAmount,
            deductionProfileKey: (string) $profileKey,
            partialDifferenceReason: isset($input['partial_difference_reason'])
                ? (string) $input['partial_difference_reason']
                : null,
            partialDifferenceNotes: isset($input['partial_difference_notes'])
                ? (string) $input['partial_difference_notes']
                : null,
        );
    }

    public function assertValid(RefundCalculationResult $calculation, bool $requirePartialReason = true): void
    {
        if ($calculation->cancellationCharges < 0
            || $calculation->gstOnCancellation < 0
            || $calculation->otherDeduction < 0) {
            throw ValidationException::withMessages([
                'cancellation_charges' => 'Deduction amounts cannot be negative.',
            ]);
        }

        if ($calculation->refundAmount < 0.01) {
            throw ValidationException::withMessages([
                'refund_amount' => 'Refund amount must be at least 0.01.',
            ]);
        }

        if ($calculation->refundAmount > $calculation->maximumRefundable + 0.001) {
            throw ValidationException::withMessages([
                'refund_amount' => 'Refund amount cannot exceed the maximum refundable amount.',
            ]);
        }

        if (! $requirePartialReason) {
            return;
        }

        if ($calculation->refundAmount < $calculation->maximumRefundable - 0.001) {
            $reason = $calculation->partialDifferenceReason;

            if ($reason === null || $reason === '') {
                throw ValidationException::withMessages([
                    'partial_difference_reason' => 'A reason for difference is required for partial refunds.',
                ]);
            }

            if (RefundDifferenceReason::tryFrom($reason) === null) {
                throw ValidationException::withMessages([
                    'partial_difference_reason' => 'Select a valid reason for difference.',
                ]);
            }

            if ($reason === RefundDifferenceReason::Other->value
                && blank($calculation->partialDifferenceNotes)) {
                throw ValidationException::withMessages([
                    'partial_difference_notes' => 'Notes are required when the difference reason is Other.',
                ]);
            }
        }
    }
}
