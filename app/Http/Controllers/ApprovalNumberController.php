<?php

namespace App\Http\Controllers;

use App\Http\Requests\LinkApprovalIncidentsRequest;
use App\Http\Requests\StoreApprovalNumberRequest;
use App\Models\ApprovalNumber;
use App\Models\Incident;
use App\Models\Order;
use App\Services\ApprovalNumberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ApprovalNumberController extends Controller
{
    public function __construct(
        private readonly ApprovalNumberService $approvalNumberService,
    ) {
        $this->authorizeResource(ApprovalNumber::class, 'approval', [
            'except' => ['edit', 'update'],
        ]);
    }

    public function index(Request $request): View
    {
        $approvals = ApprovalNumber::query()
            ->with('creator')
            ->withCount('incidents')
            ->when($request->filled('approval_number'), function ($query) use ($request) {
                $query->where(
                    'approval_number',
                    'like',
                    '%'.$request->string('approval_number')->trim().'%',
                );
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('approvals.index', [
            'approvals' => $approvals,
            'filters' => $request->only(['approval_number']),
        ]);
    }

    public function create(): View
    {
        return view('approvals.create');
    }

    public function store(StoreApprovalNumberRequest $request): RedirectResponse
    {
        $approval = $this->approvalNumberService->create(
            user: $request->user(),
            description: $request->string('description')->trim()->toString() ?: null,
            request: $request,
        );

        return redirect()
            ->route('approvals.show', $approval)
            ->with('status', 'approval-created');
    }

    public function show(ApprovalNumber $approval): View
    {
        $approval->load([
            'creator',
            'incidents.order',
            'incidents.creator',
        ])->loadCount('incidents');

        $linkedOrders = Order::query()
            ->whereIn('id', $approval->incidents->pluck('order_id')->filter()->unique())
            ->orderBy('order_id')
            ->get();

        return view('approvals.show', [
            'approval' => $approval,
            'linkedOrders' => $linkedOrders,
            'remainingSlots' => max(ApprovalNumber::MAX_INCIDENTS - $approval->incidents_count, 0),
        ]);
    }

    public function destroy(Request $request, ApprovalNumber $approval): RedirectResponse
    {
        $this->approvalNumberService->delete(
            approval: $approval,
            user: $request->user(),
            request: $request,
        );

        return redirect()
            ->route('approvals.index')
            ->with('status', 'approval-deleted');
    }

    public function lookupIncidents(Request $request, ApprovalNumber $approval): JsonResponse
    {
        $this->authorize('link', $approval);

        $term = $request->string('q')->trim()->toString();

        if ($term === '') {
            return response()->json([]);
        }

        $linkedIncidentIds = $approval->incidents()->pluck('incidents.id');

        $incidents = Incident::query()
            ->with('order:id,order_id,serial_number,product_name')
            ->whereNotIn('id', $linkedIncidentIds)
            ->where(function ($query) use ($term) {
                $query->where('reference_no', 'like', "%{$term}%")
                    ->orWhere('title', 'like', "%{$term}%")
                    ->orWhereHas('order', function ($orderQuery) use ($term) {
                        $orderQuery->where('order_id', 'like', "%{$term}%")
                            ->orWhere('serial_number', 'like', "%{$term}%");
                    });
            })
            ->orderBy('reference_no')
            ->limit(10)
            ->get(['id', 'reference_no', 'title', 'status', 'order_id']);

        return response()->json($incidents->map(function (Incident $incident) {
            return [
                'id' => $incident->id,
                'reference_no' => $incident->reference_no,
                'title' => $incident->title,
                'status' => $incident->status->label(),
                'order_id' => $incident->order?->order_id,
                'serial_number' => $incident->order?->serial_number,
                'product_name' => $incident->order?->product_name,
            ];
        }));
    }

    public function linkIncidents(LinkApprovalIncidentsRequest $request, ApprovalNumber $approval): RedirectResponse
    {
        $this->authorize('link', $approval);

        $linkedCount = $this->approvalNumberService->linkIncidents(
            approval: $approval,
            incidentIds: $request->validated('incident_ids'),
            user: $request->user(),
            request: $request,
        );

        if ($linkedCount === 0) {
            return redirect()
                ->route('approvals.show', $approval)
                ->with('status', 'approval-incidents-already-linked');
        }

        return redirect()
            ->route('approvals.show', $approval)
            ->with('status', 'approval-incidents-linked');
    }

    public function unlinkIncident(Request $request, ApprovalNumber $approval, Incident $incident): RedirectResponse
    {
        $this->authorize('link', $approval);

        $this->approvalNumberService->unlinkIncident(
            approval: $approval,
            incident: $incident,
            user: $request->user(),
            request: $request,
        );

        return redirect()
            ->route('approvals.show', $approval)
            ->with('status', 'approval-incident-unlinked');
    }
}
