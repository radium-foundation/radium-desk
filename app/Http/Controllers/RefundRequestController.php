<?php

namespace App\Http\Controllers;

use App\Enums\RefundStatus;
use App\Http\Requests\ApproveRefundRequestRequest;
use App\Http\Requests\RejectRefundRequestRequest;
use App\Http\Requests\StoreRefundRequestRequest;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\RefundRequestService;
use App\Services\RemarkTimelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RefundRequestController extends Controller
{
    public function __construct(
        private readonly RefundRequestService $refundRequestService,
        private readonly RemarkTimelineService $remarkTimelineService,
    ) {
        $this->authorizeResource(RefundRequest::class, 'refund', [
            'except' => ['edit', 'update'],
        ]);
    }

    public function index(Request $request): View
    {
        $refunds = RefundRequest::query()
            ->with(['order', 'incident', 'requester'])
            ->when($request->filled('reference_no'), function ($query) use ($request) {
                $query->where(
                    'reference_no',
                    'like',
                    '%'.$request->string('reference_no')->trim().'%',
                );
            })
            ->when($request->filled('order_id'), function ($query) use ($request) {
                $query->whereHas('order', function ($orderQuery) use ($request) {
                    $orderQuery->where(
                        'order_id',
                        'like',
                        '%'.$request->string('order_id')->trim().'%',
                    );
                });
            })
            ->when($request->filled('incident_reference_no'), function ($query) use ($request) {
                $query->whereHas('incident', function ($incidentQuery) use ($request) {
                    $incidentQuery->where(
                        'reference_no',
                        'like',
                        '%'.$request->string('incident_reference_no')->trim().'%',
                    );
                });
            })
            ->when($request->filled('status'), function ($query) use ($request) {
                $query->where('status', $request->string('status')->trim());
            })
            ->when($request->filled('requested_by'), function ($query) use ($request) {
                $query->where('requested_by', $request->integer('requested_by'));
            })
            ->when($request->filled('date_from'), function ($query) use ($request) {
                $query->whereDate('created_at', '>=', $request->string('date_from')->trim());
            })
            ->when($request->filled('date_to'), function ($query) use ($request) {
                $query->whereDate('created_at', '<=', $request->string('date_to')->trim());
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();

        $requesters = User::query()
            ->whereIn('id', RefundRequest::query()->distinct()->pluck('requested_by'))
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('refunds.index', [
            'refunds' => $refunds,
            'requesters' => $requesters,
            'filters' => $request->only([
                'reference_no',
                'order_id',
                'incident_reference_no',
                'status',
                'requested_by',
                'date_from',
                'date_to',
            ]),
        ]);
    }

    public function create(Request $request): View
    {
        $selectedOrder = null;
        $selectedIncident = null;

        if ($request->filled('order')) {
            $selectedOrder = Order::query()->find($request->integer('order'));
        }

        if ($request->filled('incident')) {
            $selectedIncident = Incident::query()->with('order')->find($request->integer('incident'));
            $selectedOrder ??= $selectedIncident?->order;
        }

        return view('refunds.create', [
            'refund' => new RefundRequest,
            'selectedOrder' => $selectedOrder,
            'selectedIncident' => $selectedIncident,
        ]);
    }

    public function store(StoreRefundRequestRequest $request): RedirectResponse
    {
        $refund = $this->refundRequestService->create(
            user: $request->user(),
            data: $request->validated(),
            request: $request,
        );

        return redirect()
            ->route('refunds.show', $refund)
            ->with('status', 'refund-created');
    }

    public function show(RefundRequest $refund): View
    {
        $refund->load(['order', 'incident', 'requester', 'reviewer']);

        return view('refunds.show', [
            'refund' => $refund,
            'timelineRemarks' => $this->remarkTimelineService->forRemarkable($refund),
        ]);
    }

    public function destroy(Request $request, RefundRequest $refund): RedirectResponse
    {
        $this->refundRequestService->delete(
            refund: $refund,
            user: $request->user(),
            request: $request,
        );

        return redirect()
            ->route('refunds.index')
            ->with('status', 'refund-deleted');
    }

    public function lookupIncidents(Request $request): JsonResponse
    {
        $this->authorize('create', RefundRequest::class);

        $orderId = $request->integer('order_id');
        $term = $request->string('q')->trim()->toString();

        if ($orderId <= 0) {
            return response()->json([]);
        }

        $incidents = Incident::query()
            ->where('order_id', $orderId)
            ->when($term !== '', function ($query) use ($term) {
                $query->where(function ($builder) use ($term) {
                    $builder->where('reference_no', 'like', "%{$term}%")
                        ->orWhere('title', 'like', "%{$term}%");
                });
            })
            ->orderBy('reference_no')
            ->limit(15)
            ->get(['id', 'reference_no', 'title', 'status']);

        return response()->json($incidents->map(fn (Incident $incident) => [
            'id' => $incident->id,
            'reference_no' => $incident->reference_no,
            'title' => $incident->title,
            'status' => $incident->status->label(),
        ]));
    }

    public function approve(ApproveRefundRequestRequest $request, RefundRequest $refund): RedirectResponse
    {
        $this->authorize('review', $refund);

        $this->refundRequestService->approve(
            refund: $refund,
            user: $request->user(),
            reviewNotes: $request->string('review_notes')->trim()->toString() ?: null,
            refundTransactionId: $request->string('refund_transaction_id')->trim()->toString(),
            request: $request,
        );

        return redirect()
            ->route('refunds.show', $refund)
            ->with('status', 'refund-approved');
    }

    public function reject(RejectRefundRequestRequest $request, RefundRequest $refund): RedirectResponse
    {
        $this->authorize('review', $refund);

        $this->refundRequestService->reject(
            refund: $refund,
            user: $request->user(),
            reviewNotes: $request->string('review_notes')->trim()->toString(),
            request: $request,
        );

        return redirect()
            ->route('refunds.show', $refund)
            ->with('status', 'refund-rejected');
    }
}
