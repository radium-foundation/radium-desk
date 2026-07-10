<?php

namespace App\Http\Controllers;

use App\Enums\IncidentSource;
use App\Enums\NewContactIntent;
use App\Http\Requests\SearchCustomerIntakeRequest;
use App\Http\Requests\StoreCustomerIntakeRequest;
use App\Models\Incident;
use App\Models\Order;
use App\Services\CustomerIntakeSearchService;
use App\Services\CustomerIntakeService;
use App\Services\Inquiry\InquiryOrderLinkService;
use App\Services\QuickServiceRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class QuickServiceRequestController extends Controller
{
    public function __construct(
        private readonly CustomerIntakeSearchService $customerIntakeSearchService,
        private readonly CustomerIntakeService $customerIntakeService,
        private readonly QuickServiceRequestService $quickServiceRequestService,
        private readonly InquiryOrderLinkService $inquiryOrderLinkService,
    ) {}

    public function search(SearchCustomerIntakeRequest $request): JsonResponse
    {
        $result = $this->customerIntakeSearchService->search(
            phone: $request->string('phone')->trim()->toString() ?: null,
            orderId: $request->string('order_id')->trim()->toString() ?: null,
            serialNumber: $request->string('serial_number')->trim()->toString() ?: null,
            user: $request->user(),
        );

        return response()->json($this->customerIntakeSearchService->toSearchPayload(
            $result,
            [
                'phone' => $request->string('phone')->trim()->toString() ?: null,
                'order_id' => $request->string('order_id')->trim()->toString() ?: null,
                'serial_number' => $request->string('serial_number')->trim()->toString() ?: null,
                'email' => null,
            ],
        ));
    }

    public function store(StoreCustomerIntakeRequest $request): RedirectResponse|JsonResponse
    {
        $source = IncidentSource::fromIntakeKey($request->string('source')->toString());
        $notes = $request->string('notes')->trim()->toString();
        $notes = $notes === '' ? null : $notes;
        $highPriority = $request->boolean('high_priority');
        $action = $request->string('action')->toString();

        if ($action === 'existing_order') {
            $order = Order::query()->findOrFail($request->integer('matched_order_id'));

            if ($request->filled('serial_number')) {
                $this->quickServiceRequestService->assertSerialMatchesOrder(
                    $order,
                    strtoupper(trim($request->string('serial_number')->toString())),
                );
            }

            if ($request->boolean('open_only')) {
                return redirect()
                    ->route('orders.show', $order)
                    ->with('status', 'order-found');
            }

            $intakePhone = $request->string('phone')->trim()->toString() ?: null;
            $linkableInquiry = $this->inquiryOrderLinkService->findLinkableInquiryIncident(
                targetOrder: $order,
                phone: $intakePhone,
            );

            if ($linkableInquiry !== null) {
                $incident = $this->inquiryOrderLinkService->linkToOrder(
                    incident: $linkableInquiry,
                    targetOrder: $order,
                    actor: $request->user(),
                );

                return $this->respondLinked($request, $incident, $order);
            }

            $incident = $this->customerIntakeService->createForExistingOrder(
                user: $request->user(),
                order: $order,
                source: $source,
                notes: $notes,
                highPriority: $highPriority,
            );

            return $this->respondCreated($request, $incident);
        }

        if ($action === 'legacy_radiumbox') {
            $incident = $this->customerIntakeService->createLegacyFromRadiumBox(
                user: $request->user(),
                orderId: $request->string('legacy_order_id')->trim()->toString(),
                source: $source,
                notes: $notes,
                highPriority: $highPriority,
                phone: $request->string('phone')->trim()->toString() ?: null,
            );

            return $this->respondCreated($request, $incident);
        }

        if ($action === 'legacy_import') {
            $incident = $this->customerIntakeService->importLegacyOrder(
                user: $request->user(),
                orderId: $request->string('legacy_order_id')->trim()->toString(),
                source: $source,
                notes: $notes,
                highPriority: $highPriority,
                phone: $request->string('phone')->trim()->toString() ?: null,
            );

            return $this->respondCreated($request, $incident);
        }

        $incident = $this->customerIntakeService->createNewContact(
            user: $request->user(),
            intent: NewContactIntent::from($request->string('intent')->toString()),
            source: $source,
            customerName: $request->string('customer_name')->trim()->toString() ?: null,
            phone: $request->string('phone')->trim()->toString() ?: null,
            serialNumber: $request->string('serial_number')->trim()->toString() ?: null,
            product: $request->string('product')->trim()->toString() ?: null,
            notes: $notes,
            highPriority: $highPriority,
        );

        return $this->respondCreated($request, $incident);
    }

    private function respondCreated(StoreCustomerIntakeRequest $request, Incident $incident): RedirectResponse|JsonResponse
    {
        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Service Case '.$incident->display_reference.' created',
                'incident_id' => $incident->id,
                'display_reference' => $incident->display_reference,
                'customer_360_url' => route('dashboard.service-cases.customer-360', $incident),
            ]);
        }

        return $this->createdRedirect($incident);
    }

    private function respondLinked(
        StoreCustomerIntakeRequest $request,
        Incident $incident,
        Order $order,
    ): RedirectResponse|JsonResponse {
        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Service Case '.$incident->display_reference.' linked to '.$order->order_id,
                'incident_id' => $incident->id,
                'display_reference' => $incident->display_reference,
                'linked_order_id' => $order->order_id,
                'customer_360_url' => route('dashboard.service-cases.customer-360', $incident),
            ]);
        }

        return redirect()
            ->route('dashboard')
            ->with('status', 'service-case-linked')
            ->with('service_case_reference', $incident->display_reference)
            ->with('linked_order_id', $order->order_id)
            ->with('open_customer_360_incident_id', $incident->id);
    }

    private function createdRedirect(Incident $incident): RedirectResponse
    {
        return redirect()
            ->route('dashboard')
            ->with('status', 'service-case-created')
            ->with('service_case_reference', $incident->display_reference)
            ->with('open_customer_360_incident_id', $incident->id);
    }
}
