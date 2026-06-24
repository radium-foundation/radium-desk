<?php

namespace App\Http\Controllers;

use App\Enums\IncidentSource;
use App\Http\Requests\StoreQuickServiceRequestRequest;
use App\Services\QuickServiceRequestService;
use Illuminate\Http\RedirectResponse;

class QuickServiceRequestController extends Controller
{
    public function __construct(
        private readonly QuickServiceRequestService $quickServiceRequestService,
    ) {}

    public function store(StoreQuickServiceRequestRequest $request): RedirectResponse
    {
        $notes = $request->string('notes')->trim()->toString();

        $incident = $this->quickServiceRequestService->create(
            user: $request->user(),
            orderId: $request->string('order_id')->trim()->toString(),
            serialNumber: $request->string('serial_number')->trim()->toString(),
            product: $request->string('product')->trim()->toString(),
            source: IncidentSource::from($request->string('source')->toString()),
            notes: $notes === '' ? null : $notes,
            highPriority: $request->boolean('high_priority'),
        );

        return redirect()
            ->route('dashboard')
            ->with('status', 'service-case-created')
            ->with('service_case_reference', $incident->reference_no);
    }
}
