<?php

namespace App\Http\Controllers;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Http\Requests\StoreIncidentRequest;
use App\Http\Requests\UpdateIncidentRequest;
use App\Models\Incident;
use App\Models\Order;
use App\Services\IncidentReferenceService;
use App\Services\RemarkTimelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class IncidentController extends Controller
{
    public function __construct(
        private readonly IncidentReferenceService $referenceService,
        private readonly RemarkTimelineService $remarkTimelineService,
    ) {
        $this->authorizeResource(Incident::class, 'incident');
    }

    public function index(Request $request): View
    {
        $incidents = Incident::query()
            ->with(['order', 'creator'])
            ->when($request->filled('order_id'), function ($query) use ($request) {
                $query->whereHas('order', function ($orderQuery) use ($request) {
                    $orderQuery->where('order_id', 'like', '%'.$request->string('order_id')->trim().'%');
                });
            })
            ->when($request->filled('reference_no'), function ($query) use ($request) {
                $query->where('reference_no', 'like', '%'.$request->string('reference_no')->trim().'%');
            })
            ->when($request->filled('category'), function ($query) use ($request) {
                $query->where('category', $request->string('category')->trim());
            })
            ->when($request->filled('status'), function ($query) use ($request) {
                $query->where('status', $request->string('status')->trim());
            })
            ->when($request->filled('source'), function ($query) use ($request) {
                $query->where('source', $request->string('source')->trim());
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

        $categories = Incident::query()
            ->select('category')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');

        return view('incidents.index', [
            'incidents' => $incidents,
            'categories' => $categories,
            'filters' => $request->only([
                'order_id',
                'reference_no',
                'category',
                'status',
                'source',
                'date_from',
                'date_to',
            ]),
        ]);
    }

    public function create(Request $request): View
    {
        $selectedOrder = null;

        if ($request->filled('order')) {
            $selectedOrder = Order::query()->find($request->integer('order'));
        }

        return view('incidents.create', [
            'incident' => new Incident([
                'status' => IncidentStatus::Open,
                'source' => IncidentSource::Internal,
            ]),
            'selectedOrder' => $selectedOrder,
        ]);
    }

    public function store(StoreIncidentRequest $request): RedirectResponse
    {
        $incident = Incident::query()->create([
            ...$request->validated(),
            'reference_no' => $this->referenceService->generate(),
            'status' => $request->enum('status', IncidentStatus::class) ?? IncidentStatus::Open,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        return redirect()
            ->route('incidents.show', $incident)
            ->with('status', 'incident-created');
    }

    public function show(Incident $incident): View
    {
        $incident->load([
            'order',
            'creator',
            'updater',
            'approvalNumbers',
            'refundRequests',
        ]);

        return view('incidents.show', [
            'incident' => $incident,
            'timelineRemarks' => $this->remarkTimelineService->forRemarkable($incident),
        ]);
    }

    public function edit(Incident $incident): View
    {
        $incident->load('order');

        return view('incidents.edit', [
            'incident' => $incident,
        ]);
    }

    public function update(UpdateIncidentRequest $request, Incident $incident): RedirectResponse
    {
        $incident->update([
            ...$request->validated(),
            'updated_by' => $request->user()->id,
        ]);

        return redirect()
            ->route('incidents.show', $incident)
            ->with('status', 'incident-updated');
    }

    public function destroy(Incident $incident): RedirectResponse
    {
        $incident->delete();

        return redirect()
            ->route('incidents.index')
            ->with('status', 'incident-deleted');
    }
}
