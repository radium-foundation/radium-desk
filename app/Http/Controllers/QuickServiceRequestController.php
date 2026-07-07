<?php

namespace App\Http\Controllers;

use App\Enums\IncidentSource;
use App\Enums\NewContactIntent;
use App\Http\Requests\SearchCustomerIntakeRequest;
use App\Http\Requests\StoreCustomerIntakeRequest;
use App\Models\Order;
use App\Services\CustomerIntakeSearchService;
use App\Services\CustomerIntakeService;
use App\Services\QuickServiceRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class QuickServiceRequestController extends Controller
{
    public function __construct(
        private readonly CustomerIntakeSearchService $customerIntakeSearchService,
        private readonly CustomerIntakeService $customerIntakeService,
        private readonly QuickServiceRequestService $quickServiceRequestService,
    ) {}

    public function search(SearchCustomerIntakeRequest $request): JsonResponse
    {
        $result = $this->customerIntakeSearchService->search(
            phone: $request->string('phone')->trim()->toString() ?: null,
            orderId: $request->string('order_id')->trim()->toString() ?: null,
            serialNumber: $request->string('serial_number')->trim()->toString() ?: null,
        );

        return response()->json([
            'classification' => $result->classification->value,
            'classification_label' => $result->classification->label(),
            'matches' => $result->matches,
            'legacy_source' => $result->legacySource,
            'legacy_preview' => $result->legacyPreview?->toArray(),
            'requires_confirmation' => $result->requiresConfirmation,
            'legacy_preview_message' => $result->requiresConfirmation
                ? 'Legacy order found. Create service case?'
                : null,
        ]);
    }

    public function store(StoreCustomerIntakeRequest $request): RedirectResponse
    {
        $source = IncidentSource::from($request->string('source')->toString());
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

            $incident = $this->customerIntakeService->createForExistingOrder(
                user: $request->user(),
                order: $order,
                source: $source,
                notes: $notes,
                highPriority: $highPriority,
            );

            return $this->createdRedirect($incident->display_reference);
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

            return $this->createdRedirect($incident->display_reference);
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

            return $this->createdRedirect($incident->display_reference);
        }

        $incident = $this->customerIntakeService->createNewContact(
            user: $request->user(),
            intent: NewContactIntent::from($request->string('intent')->toString()),
            source: $source,
            phone: $request->string('phone')->trim()->toString() ?: null,
            serialNumber: $request->string('serial_number')->trim()->toString() ?: null,
            product: $request->string('product')->trim()->toString() ?: null,
            notes: $notes,
            highPriority: $highPriority,
        );

        return $this->createdRedirect($incident->display_reference);
    }

    private function createdRedirect(string $reference): RedirectResponse
    {
        return redirect()
            ->route('dashboard')
            ->with('status', 'service-case-created')
            ->with('service_case_reference', $reference)
            ->with('reopen_quick_create', true);
    }
}
