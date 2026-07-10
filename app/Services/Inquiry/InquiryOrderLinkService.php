<?php

namespace App\Services\Inquiry;

use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\DashboardBroadcastService;
use App\Services\Interakt\InteraktCustomerMatcher;
use App\Services\ServiceCaseAssignmentEligibilityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InquiryOrderLinkService
{
    public const AUDIT_EVENT = 'inquiry.linked_to_order';

    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly ServiceCaseAssignmentEligibilityService $assignmentEligibilityService,
        private readonly InteraktCustomerMatcher $customerMatcher,
        private readonly DashboardBroadcastService $dashboardBroadcastService,
    ) {}

    public function linkToOrder(Incident $incident, Order $targetOrder, User $actor): Incident
    {
        $incident->loadMissing('order');
        $inquiryOrder = $incident->order;

        $this->assertCanLink($incident, $targetOrder);

        return DB::transaction(function () use ($incident, $inquiryOrder, $targetOrder, $actor): Incident {
            $oldOrderId = $incident->order_id;
            $referenceNo = $incident->reference_no;

            $incident->update([
                'order_id' => $targetOrder->id,
                'inquiry_origin_order_id' => $inquiryOrder->id,
                'updated_by' => $actor->id,
            ]);

            $freshIncident = $incident->fresh(['order', 'assignee', 'inquiryOriginOrder']);

            $this->auditLogService->log(
                userId: $actor->id,
                event: self::AUDIT_EVENT,
                auditable: $freshIncident,
                oldValues: [
                    'order_id' => $oldOrderId,
                    'inquiry_order_id' => $inquiryOrder->order_id,
                ],
                newValues: [
                    'order_id' => $targetOrder->id,
                    'rd_order_id' => $targetOrder->order_id,
                    'reference_no' => $referenceNo,
                    'inquiry_origin_order_id' => $inquiryOrder->id,
                ],
            );

            $this->assignmentEligibilityService->evaluateAssignmentEligibility($targetOrder, $actor);
            $this->dashboardBroadcastService->serviceCaseAssigned($freshIncident->fresh(['order', 'assignee']), $actor);

            return $freshIncident->fresh(['order', 'assignee', 'inquiryOriginOrder']);
        });
    }

    public function findLinkableInquiryIncident(Order $targetOrder, ?string $phone = null): ?Incident
    {
        if ($targetOrder->isInquiryOrder()) {
            return null;
        }

        $storedPhones = $this->matchingPhonesForLink($targetOrder, $phone);

        if ($storedPhones === []) {
            return null;
        }

        $candidates = Incident::query()
            ->with('order')
            ->whereNull('inquiry_origin_order_id')
            ->whereIn('status', IncidentStatus::operationallyActive())
            ->whereHas('order', function ($query) use ($storedPhones): void {
                $query->where('order_id', 'like', 'INQ-%')
                    ->whereIn('customer_phone', $storedPhones);
            })
            ->latest()
            ->get();

        foreach ($candidates as $candidate) {
            if (! $this->customerPhonesAlign($candidate->order, $targetOrder, $storedPhones)) {
                continue;
            }

            try {
                $this->assertCanLink($candidate, $targetOrder);

                return $candidate;
            } catch (ValidationException) {
                continue;
            }
        }

        return null;
    }

    public function resolveTargetOrder(string $orderId): Order
    {
        $normalized = trim($orderId);

        if ($normalized === '') {
            throw ValidationException::withMessages([
                'order_id' => 'Order ID is required.',
            ]);
        }

        $order = Order::query()->where('order_id', $normalized)->first();

        if ($order === null) {
            throw ValidationException::withMessages([
                'order_id' => 'Order not found.',
            ]);
        }

        return $order;
    }

    public function assertCanLink(Incident $incident, Order $targetOrder): void
    {
        $incident->loadMissing('order');
        $currentOrder = $incident->order;

        if ($currentOrder === null) {
            throw ValidationException::withMessages([
                'incident' => 'Service case is not linked to an order.',
            ]);
        }

        if (! $currentOrder->isInquiryOrder()) {
            throw ValidationException::withMessages([
                'incident' => 'Only enquiry service cases can be linked to a real order.',
            ]);
        }

        if ($incident->inquiry_origin_order_id !== null) {
            throw ValidationException::withMessages([
                'incident' => 'This service case has already been linked to an order.',
            ]);
        }

        if ($targetOrder->isInquiryOrder()) {
            throw ValidationException::withMessages([
                'order_id' => 'Cannot link an enquiry to another enquiry order.',
            ]);
        }

        if ($incident->status === IncidentStatus::Closed) {
            throw ValidationException::withMessages([
                'incident' => 'Closed service cases cannot be linked to an order.',
            ]);
        }

        if ($this->hasConflictingActiveCase($incident, $targetOrder)) {
            throw ValidationException::withMessages([
                'order_id' => 'This order already has another active service case.',
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function matchingPhonesForLink(Order $targetOrder, ?string $phone): array
    {
        $storedPhones = [];

        foreach (array_filter([$phone, $targetOrder->customer_phone]) as $candidate) {
            $storedPhones = array_merge(
                $storedPhones,
                $this->customerMatcher->matchingStoredPhones(null, $candidate),
            );
        }

        return array_values(array_unique($storedPhones));
    }

    /**
     * @param  list<string>  $storedPhones
     */
    private function customerPhonesAlign(?Order $inquiryOrder, Order $targetOrder, array $storedPhones): bool
    {
        if ($inquiryOrder === null || $storedPhones === []) {
            return false;
        }

        $inquiryPhones = $this->customerMatcher->matchingStoredPhones(
            null,
            (string) $inquiryOrder->customer_phone,
        );

        if ($inquiryPhones === []) {
            return false;
        }

        if (array_intersect($inquiryPhones, $storedPhones) !== []) {
            return true;
        }

        if ($targetOrder->customer_phone === null) {
            return false;
        }

        $targetPhones = $this->customerMatcher->matchingStoredPhones(
            null,
            (string) $targetOrder->customer_phone,
        );

        return array_intersect($inquiryPhones, $targetPhones) !== [];
    }

    private function hasConflictingActiveCase(Incident $incident, Order $targetOrder): bool
    {
        return Incident::query()
            ->where('order_id', $targetOrder->id)
            ->whereKeyNot($incident->id)
            ->whereIn('status', IncidentStatus::operationallyActive())
            ->exists();
    }
}
