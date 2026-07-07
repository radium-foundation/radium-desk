<?php

namespace App\Http\Controllers;

use App\Enums\IncidentSource;
use App\Enums\OrderStatus;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\StoreOrderServiceCaseRequest;
use App\Http\Requests\UpdateOrderRequest;
use App\Models\Order;
use App\Services\AuditLogService;
use App\Services\OrderActivityTimelineService;
use App\Services\QuickServiceRequestService;
use App\Services\RadiumBox\RadiumBoxService;
use App\Services\RemarkTimelineService;
use App\Services\SerialValidation\SerialValidationService;
use App\Services\ServiceCaseAssignmentEligibilityService;
use App\Services\ServiceCaseAutomationMonitorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class OrderController extends Controller
{
    public function __construct(
        private readonly RemarkTimelineService $remarkTimelineService,
        private readonly OrderActivityTimelineService $orderActivityTimelineService,
        private readonly QuickServiceRequestService $quickServiceRequestService,
        private readonly AuditLogService $auditLogService,
        private readonly RadiumBoxService $radiumBoxService,
        private readonly SerialValidationService $serialValidationService,
        private readonly ServiceCaseAssignmentEligibilityService $assignmentEligibilityService,
        private readonly ServiceCaseAutomationMonitorService $automationMonitor,
    ) {
        $this->authorizeResource(Order::class, 'order');
    }

    public function index(Request $request): View
    {
        $orders = Order::query()
            ->withCount(['incidents', 'refundRequests'])
            ->when($request->filled('order_id'), function ($query) use ($request) {
                $query->where('order_id', 'like', '%'.$request->string('order_id')->trim().'%');
            })
            ->when($request->filled('serial_number'), function ($query) use ($request) {
                $query->where('serial_number', 'like', '%'.$request->string('serial_number')->trim().'%');
            })
            ->when($request->filled('customer_name'), function ($query) use ($request) {
                $query->where('customer_name', 'like', '%'.$request->string('customer_name')->trim().'%');
            })
            ->when($request->filled('transaction_id'), function ($query) use ($request) {
                $query->where('transaction_id', 'like', '%'.$request->string('transaction_id')->trim().'%');
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('orders.index', [
            'orders' => $orders,
            'filters' => $request->only(['order_id', 'serial_number', 'customer_name', 'transaction_id']),
        ]);
    }

    public function lookup(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Order::class);

        $term = $request->string('q')->trim()->toString();

        if ($term === '') {
            return response()->json([]);
        }

        $orders = Order::query()
            ->where(function ($query) use ($term) {
                $query->where('order_id', 'like', "%{$term}%")
                    ->orWhere('serial_number', 'like', "%{$term}%");
            })
            ->orderBy('order_id')
            ->limit(10)
            ->get(['id', 'order_id', 'serial_number', 'product_name', 'device_model']);

        return response()->json($orders);
    }

    public function create(): View
    {
        return view('orders.create', [
            'order' => new Order([
                'status' => OrderStatus::Active,
            ]),
        ]);
    }

    public function store(StoreOrderRequest $request): RedirectResponse
    {
        $originalSerial = strtoupper(trim($request->string('serial_number')->toString()));
        $validation = $this->serialValidationService->assertValid(
            $originalSerial,
            $request->string('product_name')->toString(),
        );

        $order = Order::query()->create([
            ...collect($request->validated())->replace([
                'serial_number' => $validation->normalizedSerial,
            ])->all(),
            'status' => OrderStatus::Active,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        if ($validation->corrected) {
            $this->serialValidationService->recordIraCorrection(
                order: $order,
                originalSerial: $originalSerial,
                correctedSerial: $order->serial_number,
                actor: $request->user(),
            );
        }

        return redirect()
            ->route('orders.show', $order)
            ->with('status', 'order-created');
    }

    public function show(Order $order): View
    {
        $order->loadCount(['incidents', 'refundRequests']);
        $order->load($this->orderWorkspaceRelationships());

        $order = $this->radiumBoxService->enrichOrderForWorkspace($order);
        $order->loadMissing($this->orderWorkspaceRelationships());

        $activeIncident = $order->activeIncident();
        if ($activeIncident !== null && ! $activeIncident->relationLoaded('assignee')) {
            $activeIncident->load('assignee');
        }

        return view('orders.show', [
            'order' => $order,
            'activeIncident' => $activeIncident,
            'timelineRemarks' => $this->remarkTimelineService->forOrder($order),
            'activityTimeline' => $this->orderActivityTimelineService->forOrder($order),
        ]);
    }

    public function createServiceCase(Order $order): View
    {
        $this->authorize('view', $order);
        $this->authorize('create', \App\Models\Incident::class);

        $order->load([
            'incidents' => fn ($query) => $query->with('assignee')->latest(),
        ]);

        $activeIncident = $order->activeIncident();
        if ($activeIncident !== null && ! $activeIncident->relationLoaded('assignee')) {
            $activeIncident->load('assignee');
        }

        return view('orders.service-cases.create', [
            'order' => $order,
            'activeIncident' => $activeIncident,
            'enabledSources' => app(\App\Services\SettingService::class)->enabledSources(),
        ]);
    }

    public function storeServiceCase(StoreOrderServiceCaseRequest $request, Order $order): RedirectResponse
    {
        $this->authorize('view', $order);

        $notes = $request->string('notes')->trim()->toString();

        $incident = $this->quickServiceRequestService->createForOrder(
            user: $request->user(),
            order: $order,
            source: IncidentSource::fromIntakeKey($request->string('source')->toString()),
            notes: $notes,
            highPriority: $request->boolean('high_priority'),
        );

        return redirect()
            ->route('incidents.show', $incident)
            ->with('status', 'service-case-created')
            ->with('service_case_reference', $incident->display_reference);
    }

    public function edit(Order $order): View
    {
        return view('orders.edit', [
            'order' => $order,
        ]);
    }

    public function update(UpdateOrderRequest $request, Order $order): RedirectResponse
    {
        $wasCompleted = $order->isTransactionLocked();
        $attributesBeforeUpdate = $order->getAttributes();
        $previousSerial = $order->serial_number;
        $previousDeviceModel = $order->device_model;
        $previousProductName = $order->product_name;
        $validated = collect($request->validated())->except('correction_reason')->all();
        $iraCorrection = null;

        if (array_key_exists('serial_number', $validated)) {
            $originalSerial = (string) $validated['serial_number'];
            $validation = $this->serialValidationService->assertValidForOrder($originalSerial, $order);
            $validated['serial_number'] = $validation->normalizedSerial;

            if ($validation->corrected) {
                $iraCorrection = [
                    'original' => $originalSerial,
                    'corrected' => $validation->normalizedSerial,
                ];
            }
        }

        $order->update([
            ...$validated,
            'updated_by' => $request->user()->id,
        ]);

        if ($iraCorrection !== null) {
            $this->serialValidationService->recordIraCorrection(
                order: $order,
                originalSerial: $iraCorrection['original'],
                correctedSerial: $iraCorrection['corrected'],
                actor: $request->user(),
            );
        }

        $correctedSerial = $previousSerial !== null
            && $previousSerial !== $order->serial_number
            && $request->user()->can('correctIdentity', $order);

        if ($correctedSerial) {
            $this->auditLogService->log(
                userId: $request->user()->id,
                event: 'order.identity.corrected',
                auditable: $order,
                oldValues: [
                    'serial_number' => $previousSerial,
                ],
                newValues: [
                    'serial_number' => $order->serial_number,
                ],
                request: $request,
            );
        }

        if ($wasCompleted) {
            $ignoredFields = ['updated_at', 'created_at', 'deleted_at', 'updated_by'];
            $changedNew = collect($order->getChanges())
                ->except($ignoredFields)
                ->map(fn (mixed $value): mixed => $this->normalizeAuditValue($value))
                ->all();

            if ($changedNew !== []) {
                $changedOld = collect(array_keys($changedNew))
                    ->mapWithKeys(fn (string $field): array => [
                        $field => $this->normalizeAuditValue($attributesBeforeUpdate[$field] ?? null),
                    ])
                    ->all();

                $this->auditLogService->log(
                    userId: $request->user()->id,
                    event: 'order.updated',
                    auditable: $order,
                    oldValues: $changedOld,
                    newValues: [
                        ...$changedNew,
                        'correction_reason' => $request->string('correction_reason')->trim()->toString(),
                    ],
                    request: $request,
                );
            }
        }

        $identityFieldsChanged = $previousSerial !== $order->serial_number
            || $previousDeviceModel !== $order->device_model
            || $previousProductName !== $order->product_name;

        if ($identityFieldsChanged) {
            $freshOrder = $order->fresh();

            $this->assignmentEligibilityService->evaluateAssignmentEligibility(
                $freshOrder,
                $request->user(),
            );

            if ($this->assignmentEligibilityService->passesValidationForOrder($freshOrder)) {
                $this->automationMonitor->recordValidationPassed($freshOrder, $request->user());
            }
        }

        return redirect()
            ->route('orders.show', $order)
            ->with('status', 'order-updated');
    }

    private function normalizeAuditValue(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \DateTimeInterface => $value->format(\DateTimeInterface::ATOM),
            default => $value,
        };
    }

    /**
     * @return array<int|string, mixed>
     */
    private function orderWorkspaceRelationships(): array
    {
        $relationships = [
            'incidents' => fn ($query) => $query->with(['creator', 'assignee'])->latest(),
            'creator',
            'transactionAssigner',
        ];

        if (Schema::hasColumn('orders', 'serial_entered_by_user_id')) {
            $relationships[] = 'serialEnterer';
        }

        if (Order::supportsDeviceModelMaster()) {
            $relationships[] = 'deviceModel';
            $relationships[] = 'deviceModelAssigner';
        }

        if (Schema::hasColumn('orders', 'legacy_imported_by_user_id')) {
            $relationships[] = 'legacyImporter';
        }

        return $relationships;
    }

    public function destroy(Order $order): RedirectResponse
    {
        $order->delete();

        return redirect()
            ->route('orders.index')
            ->with('status', 'order-deleted');
    }
}
