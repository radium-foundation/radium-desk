<?php

namespace App\Http\Controllers;

use App\Enums\PreShipmentCancellationStatus;
use App\Jobs\PreShipmentCancellation\ClosePreShipmentCancellationIncidentJob;
use App\Models\PreShipmentCancellationRequest;
use App\Services\PreShipmentCancellation\PreShipmentCancellationFinanceService;
use App\Services\PreShipmentCancellation\PreShipmentCancellationRefundLinkService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PreShipmentCancellationFinanceController extends Controller
{
    public function __construct(
        private readonly PreShipmentCancellationFinanceService $financeService,
        private readonly PreShipmentCancellationRefundLinkService $refundLinkService,
    ) {}

    public function index(): View
    {
        $this->authorize('viewCentral', PreShipmentCancellationRequest::class);

        $cases = PreShipmentCancellationRequest::query()
            ->with(['order', 'submittedBy'])
            ->whereIn('status', [
                PreShipmentCancellationStatus::Submitted,
                PreShipmentCancellationStatus::FinanceReview,
                PreShipmentCancellationStatus::FinanceApproved,
                PreShipmentCancellationStatus::HeldServiceCase,
                PreShipmentCancellationStatus::HeldExistingInvoice,
                PreShipmentCancellationStatus::HeldExecution,
            ])
            ->orderByDesc('id')
            ->paginate(25);

        return view('pre-shipment-cancellations.central-queue', [
            'cases' => $cases,
        ]);
    }

    public function approve(Request $request, PreShipmentCancellationRequest $preShipmentCancellation): RedirectResponse
    {
        $this->authorize('approve', $preShipmentCancellation);

        $updated = $this->financeService->approve($request->user(), $preShipmentCancellation);

        return redirect()
            ->route('pre-shipment-cancellations.show', $updated)
            ->with('status', 'Finance approved '.$updated->reference_no.'. Manual refund execution is still required.');
    }

    public function reject(Request $request, PreShipmentCancellationRequest $preShipmentCancellation): RedirectResponse
    {
        $this->authorize('reject', $preShipmentCancellation);

        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $updated = $this->financeService->reject(
            $request->user(),
            $preShipmentCancellation,
            $validated['rejection_reason'],
        );

        return redirect()
            ->route('pre-shipment-cancellations.show', $updated)
            ->with('status', 'Case '.$updated->reference_no.' rejected.');
    }

    public function linkRefund(Request $request, PreShipmentCancellationRequest $preShipmentCancellation): RedirectResponse
    {
        $this->authorize('linkRefund', $preShipmentCancellation);

        $refund = $this->refundLinkService->approveAndLink($request->user(), $preShipmentCancellation, $request);

        return redirect()
            ->route('pre-shipment-cancellations.show', $preShipmentCancellation->fresh())
            ->with('status', 'Refund request '.$refund->reference_no.' linked. No automatic payout was executed.');
    }

    public function dispatchIncidentRetry(Request $request, PreShipmentCancellationRequest $preShipmentCancellation): RedirectResponse
    {
        $this->authorize('retryIncident', $preShipmentCancellation);

        ClosePreShipmentCancellationIncidentJob::dispatch(
            $preShipmentCancellation->id,
            $request->user()->id,
        );

        return redirect()
            ->route('pre-shipment-cancellations.show', $preShipmentCancellation)
            ->with('status', 'Incident close retry queued for '.$preShipmentCancellation->reference_no.'.');
    }
}
