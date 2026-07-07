<?php

namespace App\Services;

use App\Data\CustomerIntakeSearchResult;
use App\Enums\CustomerIdentityType;
use App\Enums\IncidentStatus;
use App\Enums\WorkspaceContext;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\Interakt\InteraktCustomerMatcher;
use App\Services\LegacyOrder\LegacyOrderLookupService;
use Illuminate\Support\Collection;

class CustomerIntakeSearchService
{
    public function __construct(
        private readonly InteraktCustomerMatcher $customerMatcher,
        private readonly LegacyOrderLookupService $legacyOrderLookupService,
    ) {}

    public function search(
        ?string $phone = null,
        ?string $orderId = null,
        ?string $serialNumber = null,
        ?User $user = null,
    ): CustomerIntakeSearchResult {
        $phone = $this->normalizeOptional($phone);
        $orderId = $this->normalizeOptional($orderId);
        $serialNumber = $this->normalizeSerial($serialNumber);

        $matches = $this->findDeskMatches($phone, $orderId, $serialNumber);

        if ($matches->isNotEmpty()) {
            $classification = $this->classifyFromMatches($matches);

            return new CustomerIntakeSearchResult(
                classification: $classification,
                matches: $this->formatMatches($matches, $user),
                legacySource: $classification === CustomerIdentityType::Legacy ? 'desk' : null,
            );
        }

        if ($orderId !== null) {
            $legacyPreview = $this->legacyOrderLookupService->lookupLegacyPreview($orderId);

            if ($legacyPreview !== null) {
                return new CustomerIntakeSearchResult(
                    classification: CustomerIdentityType::Legacy,
                    matches: [],
                    legacySource: 'radiumbox',
                    legacyPreview: $legacyPreview,
                    requiresConfirmation: true,
                );
            }
        }

        return new CustomerIntakeSearchResult(
            classification: CustomerIdentityType::NewContact,
            matches: [],
        );
    }

    /**
     * @return Collection<int, Order>
     */
    private function findDeskMatches(?string $phone, ?string $orderId, ?string $serialNumber): Collection
    {
        $orders = collect();

        if ($orderId !== null) {
            $order = Order::query()->where('order_id', $orderId)->first();

            if ($order !== null) {
                $orders->push($order);
            }
        }

        if ($phone !== null) {
            $storedPhones = $this->customerMatcher->matchingStoredPhones(null, $phone);

            if ($storedPhones !== []) {
                $phoneOrders = Order::query()
                    ->whereIn('customer_phone', $storedPhones)
                    ->orderByDesc('id')
                    ->get();

                $orders = $orders->merge($phoneOrders);
            }
        }

        if ($serialNumber !== null) {
            $serialOrders = Order::query()
                ->where('serial_number', $serialNumber)
                ->orderByDesc('id')
                ->get();

            $orders = $orders->merge($serialOrders);
        }

        return $orders->unique('id')->values();
    }

    /**
     * @param  Collection<int, Order>  $matches
     */
    private function classifyFromMatches(Collection $matches): CustomerIdentityType
    {
        $hasCashfree = $matches->contains(
            fn (Order $order): bool => filled($order->cashfree_payment_id),
        );

        if ($hasCashfree) {
            return CustomerIdentityType::CashfreeVerified;
        }

        return CustomerIdentityType::Legacy;
    }

    /**
     * @param  Collection<int, Order>  $matches
     * @return list<array{
     *     id: int,
     *     order_id: string,
     *     customer_phone: ?string,
     *     serial_number: ?string,
     *     product_name: ?string,
     *     identity_type: string,
     *     legacy_source: ?string,
     *     existing_case: ?array{
     *         incident_id: int,
     *         reference_no: ?string,
     *         display_reference: string,
     *         status: string,
     *         status_label: string,
     *         is_closed: bool,
     *         customer_360_url: string,
     *         can_reopen: bool,
     *         reopen_url: ?string,
     *         reopen_workspace_context: string,
     *     },
     * }>
     */
    private function formatMatches(Collection $matches, ?User $user): array
    {
        if ($matches->isEmpty()) {
            return [];
        }

        $orders = Order::query()
            ->whereIn('id', $matches->pluck('id'))
            ->with([
                'incidents' => fn ($query) => $query->latest()->limit(1),
            ])
            ->get()
            ->keyBy('id');

        return $matches
            ->map(function (Order $order) use ($user, $orders): array {
                $order = $orders->get($order->id) ?? $order;
                $identityType = $this->identityTypeForOrder($order);
                /** @var Incident|null $incident */
                $incident = $order->incidents->first();

                return [
                    'id' => $order->id,
                    'order_id' => $order->order_id,
                    'customer_phone' => $order->customer_phone,
                    'serial_number' => $order->serial_number,
                    'product_name' => $order->displayDeviceModelName(),
                    'identity_type' => $identityType->value,
                    'legacy_source' => $identityType === CustomerIdentityType::Legacy ? 'desk' : null,
                    'existing_case' => $incident !== null
                        ? $this->formatExistingCase($incident, $user)
                        : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     incident_id: int,
     *     reference_no: ?string,
     *     display_reference: string,
     *     status: string,
     *     status_label: string,
     *     is_closed: bool,
     *     customer_360_url: string,
     *     can_reopen: bool,
     *     reopen_url: ?string,
     *     reopen_workspace_context: string,
     * }
     */
    private function formatExistingCase(Incident $incident, ?User $user): array
    {
        $isClosed = $incident->status === IncidentStatus::Closed;
        $canReopen = $user !== null
            && $isClosed
            && $user->can('update', $incident);

        return [
            'incident_id' => $incident->id,
            'reference_no' => $incident->reference_no,
            'display_reference' => $incident->display_reference,
            'status' => $incident->status->value,
            'status_label' => $incident->status->label(),
            'is_closed' => $isClosed,
            'customer_360_url' => route('dashboard.service-cases.customer-360', $incident),
            'can_reopen' => $canReopen,
            'reopen_url' => $canReopen ? route('incidents.workspace.action', $incident) : null,
            'reopen_workspace_context' => WorkspaceContext::ServiceCase->value,
        ];
    }

    public function identityTypeForOrder(Order $order): CustomerIdentityType
    {
        if (filled($order->cashfree_payment_id)) {
            return CustomerIdentityType::CashfreeVerified;
        }

        if (Order::isInquiryOrderId($order->order_id)) {
            return CustomerIdentityType::NewContact;
        }

        return CustomerIdentityType::Legacy;
    }

    private function normalizeOptional(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeSerial(?string $value): ?string
    {
        $normalized = $this->normalizeOptional($value);

        return $normalized !== null ? strtoupper($normalized) : null;
    }
}
