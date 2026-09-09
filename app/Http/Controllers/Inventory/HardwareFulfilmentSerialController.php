<?php

namespace App\Http\Controllers\Inventory;

use App\Enums\HardwareFulfilmentOperationalStage;
use App\Enums\HardwareFulfilmentPackageEvidenceKind;
use App\Enums\HardwareFulfilmentState;
use App\Enums\HardwareOperationsSection;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\AllocateHardwareFulfilmentSerialsRequest;
use App\Http\Requests\Inventory\AssignHardwareFulfilmentAwbRequest;
use App\Http\Requests\Inventory\AttachHardwareFulfilmentMeasuredParcelRequest;
use App\Http\Requests\Inventory\AttachHardwareFulfilmentParcelRequest;
use App\Http\Requests\Inventory\CorrectHardwareFulfilmentShippingCountryRequest;
use App\Http\Requests\Inventory\CreateHardwareFulfilmentShipmentRequest;
use App\Http\Requests\Inventory\FetchHardwareFulfilmentCourierOptionsRequest;
use App\Http\Requests\Inventory\GenerateHardwareFulfilmentLabelRequest;
use App\Http\Requests\Inventory\GenerateHardwareFulfilmentManifestRequest;
use App\Http\Requests\Inventory\IssueHardwareFulfilmentInvoiceRequest;
use App\Http\Requests\Inventory\MarkHardwareFulfilmentReadyForPickupRequest;
use App\Http\Requests\Inventory\MarkHardwareFulfilmentReadyRequest;
use App\Http\Requests\Inventory\OpenHardwareFulfilmentRequest;
use App\Http\Requests\Inventory\RequestHardwareFulfilmentPickupRequest;
use App\Http\Requests\Inventory\SearchHardwareFulfilmentSerialsRequest;
use App\Http\Requests\Inventory\SelectHardwareFulfilmentCourierRequest;
use App\Http\Requests\Inventory\StoreHardwareFulfilmentPackageEvidenceRequest;
use App\Models\HardwareFulfilment;
use App\Models\HardwareFulfilmentPackageEvidence;
use App\Models\Incident;
use App\Models\InventoryBranch;
use App\Models\Order;
use App\Models\Shipment;
use App\Services\HardwareFulfilment\Data\HardwareFulfilmentOperationalClassifier;
use App\Services\HardwareFulfilment\Data\HardwareFulfilmentStepper;
use App\Services\HardwareFulfilment\HardwareAwaitingFulfilmentQueue;
use App\Services\HardwareFulfilment\HardwareFulfilmentCountryCorrectionService;
use App\Services\HardwareFulfilment\HardwareFulfilmentEligibility;
use App\Services\HardwareFulfilment\HardwareFulfilmentInvoiceService;
use App\Services\HardwareFulfilment\HardwareFulfilmentIsolatedWorkflowService;
use App\Services\HardwareFulfilment\HardwareFulfilmentPackageEvidenceService;
use App\Services\HardwareFulfilment\HardwareFulfilmentParcelSnapshotService;
use App\Services\HardwareFulfilment\HardwareFulfilmentWorkflowService;
use App\Services\HardwareFulfilment\HardwareFulfilmentWorkQueue;
use App\Services\HardwareFulfilment\HardwareSerialAllocationService;
use App\Services\HardwareFulfilment\HardwareShipmentCourierOptionsService;
use App\Services\HardwareFulfilment\HardwareShipmentDocumentsService;
use App\Services\HardwareFulfilment\HardwareShipmentEligibility;
use App\Services\HardwareFulfilment\HardwareShipmentService;
use App\Support\HardwareFulfilment\HardwareFulfilmentAccess;
use App\Support\Inventory\InventoryBranchScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HardwareFulfilmentSerialController extends Controller
{
    public function __construct(
        private readonly HardwareSerialAllocationService $allocation,
        private readonly HardwareShipmentService $shipments,
        private readonly HardwareShipmentEligibility $shipmentEligibility,
        private readonly HardwareFulfilmentParcelSnapshotService $snapshots,
        private readonly HardwareFulfilmentCountryCorrectionService $countries,
        private readonly HardwareShipmentCourierOptionsService $couriers,
        private readonly HardwareShipmentDocumentsService $documents,
        private readonly HardwareFulfilmentPackageEvidenceService $packageEvidence,
        private readonly HardwareAwaitingFulfilmentQueue $awaitingQueue,
        private readonly HardwareFulfilmentWorkQueue $workQueue,
        private readonly HardwareFulfilmentInvoiceService $invoices,
        private readonly HardwareFulfilmentOperationalClassifier $operationalClassifier,
        private readonly HardwareFulfilmentWorkflowService $workflow,
        private readonly HardwareFulfilmentIsolatedWorkflowService $isolated,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless(HardwareFulfilmentAccess::allows($request->user()), 403);

            return $next($request);
        })->except(['downloadLabel', 'downloadManifest']);

        $this->middleware(function ($request, $next) {
            abort_unless(HardwareFulfilmentAccess::allowsDocumentDownload($request->user()), 403);

            return $next($request);
        })->only(['downloadLabel', 'downloadManifest']);
    }

    public function index(Request $request): View
    {
        $queue = trim((string) $request->query('queue', ''));
        if ($queue === '') {
            $hasOpenFilters = $request->filled('serial')
                || $request->filled('state')
                || $request->filled('branch_id')
                || $request->filled('shipment_status');
            $queue = $hasOpenFilters ? 'open' : 'work';
        }
        if ($queue === 'awaiting') {
            return $this->awaitingIndex($request);
        }
        if ($queue === 'work') {
            return $this->workIndex($request);
        }

        $state = trim((string) $request->query('state', ''));
        $order = trim((string) $request->query('order', ''));
        $serial = trim((string) $request->query('serial', ''));
        $branchId = $request->integer('branch_id') ?: null;
        $shipmentStatus = trim((string) $request->query('shipment_status', ''));

        $query = HardwareFulfilment::query()
            ->with(['commerceOrder', 'fulfilmentBranch', 'serials'])
            ->orderByDesc('id');

        if ($state !== '') {
            $query->where('state', $state);
        } else {
            $query->whereIn('state', [
                HardwareFulfilmentState::Ingested,
                HardwareFulfilmentState::ReadyForFulfilment,
                HardwareFulfilmentState::SerialsAllocated,
                HardwareFulfilmentState::InvoiceIssued,
                HardwareFulfilmentState::ShipmentCreated,
                HardwareFulfilmentState::AwbAssigned,
            ]);
        }

        if ($order !== '') {
            $query->where(function ($inner) use ($order): void {
                $inner->where('source_id', 'like', '%'.$order.'%')
                    ->orWhereHas('commerceOrder', function ($commerce) use ($order): void {
                        $commerce->where('order_no', 'like', '%'.$order.'%');
                    });
            });
        }

        if ($serial !== '') {
            $query->whereHas('serials', function ($rows) use ($serial): void {
                $rows->where('serial_number', 'like', '%'.$serial.'%');
            });
        }

        if ($branchId !== null) {
            $query->where('fulfilment_branch_id', $branchId);
        }

        if ($shipmentStatus === 'not_created') {
            $query->whereNull('shipment_id');
        } elseif ($shipmentStatus === 'created') {
            $query->whereNotNull('shipment_id')->whereNull('awb');
        } elseif ($shipmentStatus === 'awb') {
            $query->whereNotNull('awb');
        } elseif ($shipmentStatus === 'ready') {
            $query->where('state', HardwareFulfilmentState::InvoiceIssued)
                ->whereNull('shipment_id');
        }

        $fulfilments = $query->paginate(40)->withQueryString();

        $range = $this->workQueueRange($request);

        return view('inventory.hardware-fulfilments.index', [
            'queue' => 'open',
            'fulfilments' => $fulfilments,
            'awaiting' => null,
            'workRows' => null,
            'awaitingSummary' => $this->awaitingQueue->summary(),
            'workSummary' => $this->workQueue->summary($range['from'], $range['to']),
            'branches' => InventoryBranch::query()->where('is_active', true)->orderBy('code')->get(),
            'filters' => [
                'queue' => 'open',
                'order' => $order,
                'serial' => $serial,
                'branch_id' => $branchId,
                'shipment_status' => $shipmentStatus,
                'state' => $state,
                'awaiting_reason' => HardwareAwaitingFulfilmentQueue::FILTER_REVIEW,
                'stage' => '',
                'section' => '',
                'payment' => '',
                'from' => $range['from']->toDateString(),
                'to' => $range['to']->toDateString(),
            ],
            'states' => HardwareFulfilmentState::cases(),
            'stages' => HardwareFulfilmentOperationalStage::cases(),
            'sections' => HardwareOperationsSection::cases(),
        ]);
    }

    private function workIndex(Request $request): View
    {
        $range = $this->workQueueRange($request);
        $order = trim((string) $request->query('order', ''));
        $stage = trim((string) $request->query('stage', ''));
        $section = trim((string) $request->query('section', ''));
        $payment = trim((string) $request->query('payment', ''));
        $allowedPayments = ['', 'paid', 'unpaid'];
        if (! in_array($payment, $allowedPayments, true)) {
            $payment = '';
        }
        $allowedStages = array_map(
            static fn (HardwareFulfilmentOperationalStage $row): string => $row->value,
            HardwareFulfilmentOperationalStage::cases(),
        );
        if ($stage !== '' && ! in_array($stage, $allowedStages, true)) {
            $stage = '';
        }
        $allowedSections = array_map(
            static fn (HardwareOperationsSection $row): string => $row->value,
            HardwareOperationsSection::cases(),
        );
        if ($section !== '' && ! in_array($section, $allowedSections, true)) {
            $section = '';
        }

        return view('inventory.hardware-fulfilments.index', [
            'queue' => 'work',
            'fulfilments' => null,
            'awaiting' => null,
            'workRows' => $this->workQueue->paginate(
                $range['from'],
                $range['to'],
                $stage,
                $order,
                $payment,
                40,
                $section,
            ),
            'awaitingSummary' => $this->awaitingQueue->summary(),
            'workSummary' => $this->workQueue->summary($range['from'], $range['to']),
            'branches' => InventoryBranch::query()->where('is_active', true)->orderBy('code')->get(),
            'filters' => [
                'queue' => 'work',
                'order' => $order,
                'serial' => '',
                'branch_id' => null,
                'shipment_status' => '',
                'state' => '',
                'awaiting_reason' => HardwareAwaitingFulfilmentQueue::FILTER_REVIEW,
                'stage' => $stage,
                'section' => $section,
                'payment' => $payment,
                'from' => $range['from']->toDateString(),
                'to' => $range['to']->toDateString(),
            ],
            'states' => HardwareFulfilmentState::cases(),
            'stages' => HardwareFulfilmentOperationalStage::cases(),
            'sections' => HardwareOperationsSection::cases(),
        ]);
    }

    /**
     * @return array{from: Carbon, to: Carbon}
     */
    private function workQueueRange(Request $request): array
    {
        $timezone = HardwareFulfilmentEligibility::CUTOFF_TIMEZONE;
        $from = $request->filled('from')
            ? Carbon::parse((string) $request->query('from'), $timezone)->startOfDay()
            : HardwareFulfilmentEligibility::cutoffInstant();
        $to = $request->filled('to')
            ? Carbon::parse((string) $request->query('to'), $timezone)->endOfDay()
            : Carbon::now($timezone);
        $now = Carbon::now($timezone);
        if ($to->gt($now)) {
            $to = $now;
        }
        if ($from->gt($to)) {
            $from = $to->copy()->startOfDay();
        }

        return ['from' => $from, 'to' => $to];
    }

    private function awaitingIndex(Request $request): View
    {
        $order = trim((string) $request->query('order', ''));
        $reason = trim((string) $request->query('awaiting_reason', HardwareAwaitingFulfilmentQueue::FILTER_REVIEW));
        $allowed = [
            HardwareAwaitingFulfilmentQueue::FILTER_REVIEW,
            HardwareAwaitingFulfilmentQueue::FILTER_EXCLUDED,
            HardwareAwaitingFulfilmentQueue::FILTER_HISTORICAL,
            HardwareAwaitingFulfilmentQueue::FILTER_COMPLETED,
            HardwareAwaitingFulfilmentQueue::FILTER_UNPAID,
            HardwareAwaitingFulfilmentQueue::FILTER_ALL,
        ];
        if (! in_array($reason, $allowed, true)) {
            $reason = HardwareAwaitingFulfilmentQueue::FILTER_REVIEW;
        }

        $range = $this->workQueueRange($request);

        return view('inventory.hardware-fulfilments.index', [
            'queue' => 'awaiting',
            'fulfilments' => null,
            'awaiting' => $this->awaitingQueue->paginate($reason, $order),
            'workRows' => null,
            'awaitingSummary' => $this->awaitingQueue->summary(),
            'workSummary' => $this->workQueue->summary($range['from'], $range['to']),
            'branches' => InventoryBranch::query()->where('is_active', true)->orderBy('code')->get(),
            'filters' => [
                'queue' => 'awaiting',
                'order' => $order,
                'serial' => '',
                'branch_id' => null,
                'shipment_status' => '',
                'state' => '',
                'awaiting_reason' => $reason,
                'stage' => '',
                'section' => '',
                'payment' => '',
                'from' => $range['from']->toDateString(),
                'to' => $range['to']->toDateString(),
            ],
            'states' => HardwareFulfilmentState::cases(),
            'stages' => HardwareFulfilmentOperationalStage::cases(),
            'sections' => HardwareOperationsSection::cases(),
        ]);
    }

    public function show(Request $request, HardwareFulfilment $fulfilment): View
    {
        $this->assertCanOperateFulfilment($request, $fulfilment);
        $fulfilment->load([
            'commerceOrder.items',
            'serials.inventorySerial.branch',
            'fulfilmentBranch',
            'shipment.events',
            'packageEvidences',
        ]);

        $requirements = $this->allocation->requirements($fulfilment);
        $canAllocate = $fulfilment->state === HardwareFulfilmentState::ReadyForFulfilment
            && ! HardwareFulfilmentEligibility::isFrozenForFulfilment((string) $fulfilment->source_id, $fulfilment->commerceOrder)
            && collect($requirements)->every(fn (array $line): bool => $line['map_ready']);

        $shipment = $this->shipmentEligibility->inspect($fulfilment);
        $opsRow = $this->operationalClassifier->fromFulfilment($fulfilment, $shipment);
        $canIssueInvoice = $fulfilment->state === HardwareFulfilmentState::SerialsAllocated
            && ! HardwareFulfilmentEligibility::isFrozenForFulfilment((string) $fulfilment->source_id, $fulfilment->commerceOrder)
            && ($shipment->invoice === null || $shipment->invoice === '');

        return view('inventory.hardware-fulfilments.show', [
            'fulfilment' => $fulfilment,
            'requirements' => $requirements,
            'allocated' => $fulfilment->serials,
            'canAllocate' => $canAllocate,
            'derivedBranch' => $fulfilment->fulfilmentBranch,
            'shipment' => $shipment,
            'opsRow' => $opsRow,
            'stepperMilestones' => HardwareFulfilmentStepper::milestones(),
            'stepperCurrentIndex' => HardwareFulfilmentStepper::currentIndex($opsRow, $shipment),
            'stepperCurrentCaption' => HardwareFulfilmentStepper::currentCaption($opsRow),
            'canIssueInvoice' => $canIssueInvoice,
            'canCorrectCountry' => false,
            'canViewInvoice' => $shipment->invoiceId !== null,
            'invoiceShowUrl' => $shipment->invoiceId !== null
                ? route('finance.invoices.show', $shipment->invoiceId)
                : null,
            'invoicePdfUrl' => $shipment->invoiceId !== null
                ? route('finance.invoices.pdf', $shipment->invoiceId)
                : null,
            'boundShipment' => $fulfilment->shipment,
        ]);
    }

    public function actionDialog(Request $request, HardwareFulfilment $fulfilment): View
    {
        $this->assertCanOperateFulfilment($request, $fulfilment);
        $fulfilment->load([
            'commerceOrder.items',
            'supportOrder',
            'serials.inventorySerial.branch',
            'fulfilmentBranch',
            'shipment',
            'packageEvidences',
        ]);

        $ready = $this->shipmentEligibility->inspect($fulfilment);
        $row = $this->operationalClassifier->fromFulfilment($fulfilment, $ready);
        $requirements = [];
        $canAllocate = false;
        $allocateUnavailableReason = null;
        if ($row->nextAction === 'Allocate Serial') {
            try {
                $requirements = $this->allocation->requirements($fulfilment);
                $canAllocate = $fulfilment->state === HardwareFulfilmentState::ReadyForFulfilment
                    && ! HardwareFulfilmentEligibility::isFrozenForFulfilment((string) $fulfilment->source_id, $fulfilment->commerceOrder)
                    && collect($requirements)->every(fn (array $line): bool => $line['map_ready']);
                if (! $canAllocate) {
                    $allocateUnavailableReason = $this->allocateUnavailableReason($fulfilment, $requirements);
                }
            } catch (ValidationException $exception) {
                $canAllocate = false;
                $allocateUnavailableReason = collect($exception->errors())->flatten()->first()
                    ?: 'Serial allocation is not available for this order yet.';
            }
        }

        return view('inventory.hardware-fulfilments.fragments.action-dialog', [
            'fulfilment' => $fulfilment,
            'row' => $row,
            'ready' => $ready,
            'requirements' => $requirements,
            'canAllocate' => $canAllocate,
            'allocateUnavailableReason' => $allocateUnavailableReason,
            'searchUrl' => route('inventory.hardware-fulfilments.serials.search', $fulfilment),
            'showUrl' => route('inventory.hardware-fulfilments.show', $fulfilment),
            'incidentId' => $this->incidentIdFor($fulfilment),
        ]);
    }

    public function awaitingActionDialog(Request $request, Order $order): View
    {
        $row = $this->operationalClassifier->fromAwaiting($order);
        if ($row->nextAction !== 'Open Fulfilment' || ! $row->mutatingAction) {
            throw ValidationException::withMessages([
                'fulfilment' => $row->blocker ?: 'This order cannot open fulfilment from the Dashboard.',
            ]);
        }

        $incidentId = Incident::query()
            ->where('order_id', $order->id)
            ->max('id');

        return view('inventory.hardware-fulfilments.fragments.action-dialog-awaiting', [
            'order' => $order,
            'row' => $row,
            'incidentId' => $incidentId !== null ? (int) $incidentId : null,
        ]);
    }

    public function storeOpen(OpenHardwareFulfilmentRequest $request, Order $order): RedirectResponse|JsonResponse
    {
        $row = $this->operationalClassifier->fromAwaiting($order);
        if ($row->nextAction !== 'Open Fulfilment' || ! $row->mutatingAction) {
            throw ValidationException::withMessages([
                'fulfilment' => $row->blocker ?: 'This order cannot open fulfilment from the Dashboard.',
            ]);
        }

        $this->isolated->run(
            identifier: (string) $order->order_id,
            step: HardwareFulfilmentIsolatedWorkflowService::STEP_INGEST,
            actor: $request->user(),
        );

        $fulfilment = HardwareFulfilment::query()
            ->where(function ($query) use ($order): void {
                $query->where('source_id', $order->order_id)
                    ->orWhere('support_order_id', $order->id);
            })
            ->first();

        if ($fulfilment === null) {
            throw ValidationException::withMessages([
                'fulfilment' => 'Recovered fulfilment did not open a Hardware Fulfilment.',
            ]);
        }

        return $this->mutationResponse($request, $fulfilment, 'Hardware fulfilment opened.');
    }

    public function search(SearchHardwareFulfilmentSerialsRequest $request, HardwareFulfilment $fulfilment): JsonResponse
    {
        $this->assertCanOperateFulfilment($request, $fulfilment);

        $branch = trim($request->string('branch')->toString());

        return response()->json([
            'serials' => $this->allocation->searchAvailable(
                $fulfilment,
                $request->integer('commerce_order_item_id'),
                $request->string('q')->toString(),
                20,
                $branch !== '' ? $branch : null,
                $request->user(),
            ),
        ]);
    }

    public function storeReady(MarkHardwareFulfilmentReadyRequest $request, HardwareFulfilment $fulfilment): RedirectResponse|JsonResponse
    {
        $this->assertCanOperateFulfilment($request, $fulfilment);
        $this->workflow->markReady(
            $fulfilment,
            actorType: 'user',
            actorId: $request->user()?->id,
        );

        return $this->mutationResponse($request, $fulfilment, 'Marked ready for fulfilment.');
    }

    public function store(AllocateHardwareFulfilmentSerialsRequest $request, HardwareFulfilment $fulfilment): RedirectResponse|JsonResponse
    {
        $this->assertCanOperateFulfilment($request, $fulfilment);

        $this->allocation->allocate(
            $fulfilment,
            $request->validated('serials'),
            $request->user(),
        );

        return $this->mutationResponse($request, $fulfilment, 'Serial allocated.');
    }

    public function storeInvoice(IssueHardwareFulfilmentInvoiceRequest $request, HardwareFulfilment $fulfilment): RedirectResponse|JsonResponse
    {
        $this->assertCanOperateFulfilment($request, $fulfilment);
        $invoice = $this->invoices->issueInvoice($fulfilment, $request->user());

        return $this->mutationResponse($request, $fulfilment, 'Hardware invoice '.$invoice->invoice_number.' issued.');
    }

    public function storeShipment(CreateHardwareFulfilmentShipmentRequest $request, HardwareFulfilment $fulfilment): RedirectResponse|JsonResponse
    {
        $this->assertCanOperateFulfilment($request, $fulfilment);
        $this->shipments->createShipment($fulfilment, $request->user());

        return $this->mutationResponse($request, $fulfilment, 'Shipment created.');
    }

    public function storeParcelSnapshot(AttachHardwareFulfilmentParcelRequest $request, HardwareFulfilment $fulfilment): RedirectResponse|JsonResponse
    {
        $this->assertCanOperateFulfilment($request, $fulfilment);
        $this->snapshots->attachFromCatalog($fulfilment, $request->user());

        return $this->mutationResponse($request, $fulfilment, 'Parcel snapshot attached.');
    }

    public function storeMeasuredParcel(AttachHardwareFulfilmentMeasuredParcelRequest $request, HardwareFulfilment $fulfilment): RedirectResponse|JsonResponse
    {
        $this->assertCanOperateFulfilment($request, $fulfilment);
        $validated = $request->validated();
        $this->snapshots->attachMeasured(
            $fulfilment,
            [
                'length' => $validated['length'],
                'breadth' => $validated['breadth'],
                'height' => $validated['height'],
                'weight' => $validated['weight'],
            ],
            $request->user(),
            $request->boolean('save_for_future'),
        );

        return $this->mutationResponse($request, $fulfilment, 'Packed shipment dimensions saved.');
    }

    public function downloadLabel(Request $request, HardwareFulfilment $fulfilment): RedirectResponse
    {
        $this->assertCanDownloadFulfilmentDocuments($request, $fulfilment);
        $url = $this->persistedDocumentUrl($fulfilment, 'label_url');
        abort_unless($url !== null, 404);

        return redirect()->away($url);
    }

    public function downloadManifest(Request $request, HardwareFulfilment $fulfilment): RedirectResponse
    {
        $this->assertCanDownloadFulfilmentDocuments($request, $fulfilment);
        $url = $this->persistedDocumentUrl($fulfilment, 'manifest_url');
        abort_unless($url !== null, 404);

        return redirect()->away($url);
    }

    public function storeCountry(CorrectHardwareFulfilmentShippingCountryRequest $request, HardwareFulfilment $fulfilment): RedirectResponse|JsonResponse
    {
        $this->assertCanOperateFulfilment($request, $fulfilment);
        $this->countries->correct($fulfilment, $request->validated('country'), $request->user());

        return $this->mutationResponse($request, $fulfilment, 'Shipping country recorded.');
    }

    public function storeCourierOptions(FetchHardwareFulfilmentCourierOptionsRequest $request, HardwareFulfilment $fulfilment): RedirectResponse|JsonResponse
    {
        $this->assertCanOperateFulfilment($request, $fulfilment);
        $this->couriers->fetch($fulfilment, $request->user());

        return $this->mutationResponse($request, $fulfilment, 'Courier options updated from Shiprocket.');
    }

    public function storeCourier(SelectHardwareFulfilmentCourierRequest $request, HardwareFulfilment $fulfilment): RedirectResponse|JsonResponse
    {
        $this->assertCanOperateFulfilment($request, $fulfilment);
        $this->couriers->select($fulfilment, $request->validated('courier_id'), $request->user());

        return $this->mutationResponse($request, $fulfilment, 'Courier selected.');
    }

    public function storeAwb(AssignHardwareFulfilmentAwbRequest $request, HardwareFulfilment $fulfilment): RedirectResponse|JsonResponse
    {
        $this->assertCanOperateFulfilment($request, $fulfilment);
        $this->shipments->assignAwb($fulfilment, $request->user());

        return $this->mutationResponse($request, $fulfilment, 'AWB assigned.');
    }

    public function storeLabel(GenerateHardwareFulfilmentLabelRequest $request, HardwareFulfilment $fulfilment): RedirectResponse|JsonResponse
    {
        $this->assertCanOperateFulfilment($request, $fulfilment);
        $this->documents->generateLabel($fulfilment, $request->user());

        return $this->mutationResponse($request, $fulfilment, 'Shipping label generated.');
    }

    public function storePickup(RequestHardwareFulfilmentPickupRequest $request, HardwareFulfilment $fulfilment): RedirectResponse|JsonResponse
    {
        $this->assertCanOperateFulfilment($request, $fulfilment);
        $outcome = $this->documents->requestPickup($fulfilment, $request->user());

        return $this->mutationResponse($request, $fulfilment, $outcome->flash());
    }

    public function storeManifest(GenerateHardwareFulfilmentManifestRequest $request, HardwareFulfilment $fulfilment): RedirectResponse|JsonResponse
    {
        $this->assertCanOperateFulfilment($request, $fulfilment);
        $this->documents->generateManifest($fulfilment, $request->user());

        return $this->mutationResponse($request, $fulfilment, 'Manifest generated.');
    }

    public function storePackageEvidence(StoreHardwareFulfilmentPackageEvidenceRequest $request, HardwareFulfilment $fulfilment): RedirectResponse|JsonResponse
    {
        $this->assertCanOperateFulfilment($request, $fulfilment);
        $kind = HardwareFulfilmentPackageEvidenceKind::from($request->validated('kind'));
        $this->packageEvidence->attach(
            $fulfilment,
            $kind,
            $request->file('photo'),
            $request->user(),
        );

        return $this->mutationResponse($request, $fulfilment, $kind->label().' recorded.');
    }

    public function showPackageEvidence(
        Request $request,
        HardwareFulfilment $fulfilment,
        HardwareFulfilmentPackageEvidence $evidence,
    ): StreamedResponse {
        $this->assertCanOperateFulfilment($request, $fulfilment);
        abort_unless((int) $evidence->hardware_fulfilment_id === (int) $fulfilment->id, 404);

        $disk = $evidence->disk ?: 'local';
        abort_unless(Storage::disk($disk)->exists($evidence->path), 404);

        return Storage::disk($disk)->response(
            $evidence->path,
            $evidence->original_filename ?: basename($evidence->path),
        );
    }

    public function storeReadyForPickup(MarkHardwareFulfilmentReadyForPickupRequest $request, HardwareFulfilment $fulfilment): RedirectResponse|JsonResponse
    {
        $this->assertCanOperateFulfilment($request, $fulfilment);
        $this->documents->markReadyForPickup($fulfilment, $request->user());

        return $this->mutationResponse($request, $fulfilment, 'Fulfilment marked ready for pickup.');
    }

    private function mutationResponse(Request $request, HardwareFulfilment $fulfilment, string $status): RedirectResponse|JsonResponse
    {
        if (! $request->wantsJson()) {
            return redirect()
                ->route('inventory.hardware-fulfilments.show', $fulfilment)
                ->with('status', $status);
        }

        $fulfilment->refresh()->load([
            'commerceOrder.items',
            'supportOrder',
            'serials.inventorySerial',
            'fulfilmentBranch',
            'shipment',
            'packageEvidences',
        ]);
        $ready = $this->shipmentEligibility->inspect($fulfilment);
        $row = $this->operationalClassifier->fromFulfilment($fulfilment, $ready);
        $incidentId = $this->incidentIdFor($fulfilment);

        return response()->json([
            'ok' => true,
            'status' => $status,
            'next_action' => $row->nextAction,
            'operator_status' => $row->operatorStatus(),
            'mutating' => $row->mutatingAction,
            'incident_id' => $incidentId,
            'refresh_customer360' => $incidentId !== null,
            'action_dialog_url' => $row->mutatingAction
                ? route('inventory.hardware-fulfilments.action-dialog', $fulfilment)
                : null,
            'manifest_url' => $ready->manifestUrl,
        ]);
    }

    private function incidentIdFor(HardwareFulfilment $fulfilment): ?int
    {
        if ($fulfilment->support_order_id === null) {
            return null;
        }

        $id = Incident::query()
            ->where('order_id', $fulfilment->support_order_id)
            ->max('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * @param  list<array<string, mixed>>  $requirements
     */
    private function allocateUnavailableReason(HardwareFulfilment $fulfilment, array $requirements): string
    {
        if (HardwareFulfilmentEligibility::isFrozenForFulfilment((string) $fulfilment->source_id, $fulfilment->commerceOrder)) {
            return 'Frozen pending hardware orders cannot receive serial allocation.';
        }

        if ($fulfilment->state !== HardwareFulfilmentState::ReadyForFulfilment) {
            return 'Serial allocation requires READY_FOR_FULFILMENT. Payment success is not enough.';
        }

        if ($requirements === []) {
            return 'No allocatable hardware lines are on this fulfilment.';
        }

        if (collect($requirements)->contains(fn (array $line): bool => ! ($line['map_ready'] ?? false))) {
            return 'Owner SKU map is missing for a product on this order. Allocation is blocked.';
        }

        return 'Serial allocation is not available for this order yet.';
    }

    private function assertCanDownloadFulfilmentDocuments(Request $request, HardwareFulfilment $fulfilment): void
    {
        abort_unless(HardwareFulfilmentAccess::allowsDocumentDownload($request->user()), 403);

        if (HardwareFulfilmentAccess::allows($request->user())) {
            $this->assertCanOperateFulfilment($request, $fulfilment);
        }
    }

    private function assertCanOperateFulfilment(Request $request, HardwareFulfilment $fulfilment): void
    {
        if ($fulfilment->fulfilment_branch_id === null) {
            return;
        }

        $branch = InventoryBranch::query()->find($fulfilment->fulfilment_branch_id);
        if ($branch === null) {
            throw ValidationException::withMessages([
                'branch' => 'Hardware fulfilment branch is missing.',
            ]);
        }

        InventoryBranchScope::assertCanOperate($request->user(), $branch);
    }

    private function persistedDocumentUrl(HardwareFulfilment $fulfilment, string $column): ?string
    {
        $shipment = $fulfilment->shipment;
        if ($shipment === null && $fulfilment->shipment_id !== null) {
            $shipment = Shipment::query()->find($fulfilment->shipment_id);
        }
        if ($shipment === null) {
            $shipment = Shipment::query()->where('hardware_fulfilment_id', $fulfilment->id)->first();
        }

        $url = trim((string) ($shipment?->{$column} ?? ''));

        return $url !== '' ? $url : null;
    }
}
