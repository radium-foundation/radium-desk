<?php

namespace App\Http\Controllers;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\RadiumBoxSyncTrigger;
use App\Http\Requests\StoreIncidentRequest;
use App\Http\Requests\UpdateIncidentRequest;
use App\Models\ApprovalNumber;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\Incidents\IncidentListingQuery;
use App\Services\Operations\WorkforceActivityContextService;
use App\Services\RadiumBox\RadiumBoxAutoSyncTriggerService;
use App\Services\ServiceCaseActivityTimelineService;
use App\Services\ServiceCaseAssignmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class IncidentController extends Controller
{
    public function __construct(
        private readonly IncidentReferenceService $referenceService,
        private readonly ServiceCaseActivityTimelineService $activityTimelineService,
        private readonly ServiceCaseAssignmentService $serviceCaseAssignmentService,
        private readonly RadiumBoxAutoSyncTriggerService $radiumBoxAutoSyncTriggerService,
        private readonly WorkforceActivityContextService $workforceActivityContextService,
        private readonly IncidentListingQuery $incidentListingQuery,
    ) {
        $this->authorizeResource(Incident::class, 'incident');
    }

    public function index(Request $request): View
    {
        return view('incidents.index', [
            'incidents' => $this->incidentListingQuery->paginate($request),
            'categories' => $this->incidentListingQuery->categories(),
            'filters' => $this->incidentListingQuery->filtersFrom($request),
            'embedded' => false,
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

    public function show(Request $request, Incident $incident): View
    {
        $incident->load([
            'order.legacyImporter',
            'order.incidents.assignee',
            'creator',
            'assignee',
            'updater',
            'approvalNumbers',
            'refundRequests',
        ]);

        if ($incident->order !== null) {
            $this->radiumBoxAutoSyncTriggerService->maybeDispatch(
                $incident->order,
                RadiumBoxSyncTrigger::WorkspaceOpen,
            );
        }

        if ($request->user() !== null) {
            $this->workforceActivityContextService->recordServiceCaseViewed(
                $request->user(),
                $incident,
                $request,
            );
        }

        return view('incidents.show', [
            'incident' => $incident,
            'activityTimeline' => $this->activityTimelineService->forIncident($incident),
            'reassignableAdmins' => $this->serviceCaseAssignmentService->reassignableAdmins(),
            'mentionUsers' => User::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->pluck('name'),
            'linkableApprovals' => $this->linkableApprovalsForIncident($request, $incident),
        ]);
    }

    /**
     * @return \Illuminate\Support\Collection<int, ApprovalNumber>
     */
    private function linkableApprovalsForIncident(Request $request, Incident $incident): \Illuminate\Support\Collection
    {
        if (! $request->user()?->can('approvals.link')) {
            return collect();
        }

        return ApprovalNumber::query()
            ->withCount('incidents')
            ->whereDoesntHave('incidents', fn ($query) => $query->where('incidents.id', $incident->id))
            ->latest()
            ->get()
            ->filter(fn (ApprovalNumber $approval): bool => $approval->incidents_count < ApprovalNumber::MAX_INCIDENTS)
            ->values();
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
