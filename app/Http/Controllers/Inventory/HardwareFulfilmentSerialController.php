<?php

namespace App\Http\Controllers\Inventory;

use App\Enums\HardwareFulfilmentPackageEvidenceKind;
use App\Enums\HardwareFulfilmentState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\AllocateHardwareFulfilmentSerialsRequest;
use App\Http\Requests\Inventory\AssignHardwareFulfilmentAwbRequest;
use App\Http\Requests\Inventory\AttachHardwareFulfilmentParcelRequest;
use App\Http\Requests\Inventory\CorrectHardwareFulfilmentShippingCountryRequest;
use App\Http\Requests\Inventory\CreateHardwareFulfilmentShipmentRequest;
use App\Http\Requests\Inventory\FetchHardwareFulfilmentCourierOptionsRequest;
use App\Http\Requests\Inventory\GenerateHardwareFulfilmentLabelRequest;
use App\Http\Requests\Inventory\GenerateHardwareFulfilmentManifestRequest;
use App\Http\Requests\Inventory\MarkHardwareFulfilmentReadyForPickupRequest;
use App\Http\Requests\Inventory\RequestHardwareFulfilmentPickupRequest;
use App\Http\Requests\Inventory\SearchHardwareFulfilmentSerialsRequest;
use App\Http\Requests\Inventory\SelectHardwareFulfilmentCourierRequest;
use App\Http\Requests\Inventory\StoreHardwareFulfilmentPackageEvidenceRequest;
use App\Models\HardwareFulfilment;
use App\Models\HardwareFulfilmentPackageEvidence;
use App\Models\InventoryBranch;
use App\Services\HardwareFulfilment\HardwareAwaitingFulfilmentQueue;
use App\Services\HardwareFulfilment\HardwareFulfilmentCountryCorrectionService;
use App\Services\HardwareFulfilment\HardwareFulfilmentEligibility;
use App\Services\HardwareFulfilment\HardwareFulfilmentPackageEvidenceService;
use App\Services\HardwareFulfilment\HardwareFulfilmentParcelSnapshotService;
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
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless(HardwareFulfilmentAccess::allows($request->user()), 403);

            return $next($request);
        });
    }

    public function index(Request $request): View
    {
        $queue = trim((string) $request->query('queue', 'open'));
        if ($queue === 'awaiting') {
            return $this->awaitingIndex($request);
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

        return view('inventory.hardware-fulfilments.index', [
            'queue' => 'open',
            'fulfilments' => $fulfilments,
            'awaiting' => null,
            'awaitingSummary' => $this->awaitingQueue->summary(),
            'branches' => InventoryBranch::query()->where('is_active', true)->orderBy('code')->get(),
            'filters' => [
                'queue' => 'open',
                'order' => $order,
                'serial' => $serial,
                'branch_id' => $branchId,
                'shipment_status' => $shipmentStatus,
                'state' => $state,
                'awaiting_reason' => HardwareAwaitingFulfilmentQueue::FILTER_REVIEW,
            ],
            'states' => HardwareFulfilmentState::cases(),
        ]);
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

        return view('inventory.hardware-fulfilments.index', [
            'queue' => 'awaiting',
            'fulfilments' => null,
            'awaiting' => $this->awaitingQueue->paginate($reason, $order),
            'awaitingSummary' => $this->awaitingQueue->summary(),
            'branches' => InventoryBranch::query()->where('is_active', true)->orderBy('code')->get(),
            'filters' => [
                'queue' => 'awaiting',
                'order' => $order,
                'serial' => '',
                'branch_id' => null,
                'shipment_status' => '',
                'state' => '',
                'awaiting_reason' => $reason,
            ],
            'states' => HardwareFulfilmentState::cases(),
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
            && ! HardwareFulfilmentEligibility::isFrozenSourceId((string) $fulfilment->source_id)
            && collect($requirements)->every(fn (array $line): bool => $line['map_ready']);

        $shipment = $this->shipmentEligibility->inspect($fulfilment);

        return view('inventory.hardware-fulfilments.show', [
            'fulfilment' => $fulfilment,
            'requirements' => $requirements,
            'allocated' => $fulfilment->serials,
            'canAllocate' => $canAllocate,
            'derivedBranch' => $fulfilment->fulfilmentBranch,
            'shipment' => $shipment,
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

    public function store(AllocateHardwareFulfilmentSerialsRequest $request, HardwareFulfilment $fulfilment): RedirectResponse
    {
        $this->assertCanOperateFulfilment($request, $fulfilment);

        $this->allocation->allocate(
            $fulfilment,
            $request->validated('serials'),
            $request->user(),
        );

        return redirect()
            ->route('inventory.hardware-fulfilments.show', $fulfilment)
            ->with('status', 'Serial allocated.');
    }

    public function storeShipment(CreateHardwareFulfilmentShipmentRequest $request, HardwareFulfilment $fulfilment): RedirectResponse
    {
        $this->assertCanOperateFulfilment($request, $fulfilment);
        $this->shipments->createShipment($fulfilment, $request->user());

        return redirect()
            ->route('inventory.hardware-fulfilments.show', $fulfilment)
            ->with('status', 'Shipment created.');
    }

    public function storeParcelSnapshot(AttachHardwareFulfilmentParcelRequest $request, HardwareFulfilment $fulfilment): RedirectResponse
    {
        $this->assertCanOperateFulfilment($request, $fulfilment);
        $this->snapshots->attachFromCatalog($fulfilment, $request->user());

        return redirect()
            ->route('inventory.hardware-fulfilments.show', $fulfilment)
            ->with('status', 'Parcel snapshot attached.');
    }

    public function storeCountry(CorrectHardwareFulfilmentShippingCountryRequest $request, HardwareFulfilment $fulfilment): RedirectResponse
    {
        $this->assertCanOperateFulfilment($request, $fulfilment);
        $this->countries->correct($fulfilment, $request->validated('country'), $request->user());

        return redirect()
            ->route('inventory.hardware-fulfilments.show', $fulfilment)
            ->with('status', 'Shipping country recorded.');
    }

    public function storeCourierOptions(FetchHardwareFulfilmentCourierOptionsRequest $request, HardwareFulfilment $fulfilment): RedirectResponse
    {
        $this->assertCanOperateFulfilment($request, $fulfilment);
        $this->couriers->fetch($fulfilment, $request->user());

        return redirect()
            ->route('inventory.hardware-fulfilments.show', $fulfilment)
            ->with('status', 'Courier options updated from Shiprocket.');
    }

    public function storeCourier(SelectHardwareFulfilmentCourierRequest $request, HardwareFulfilment $fulfilment): RedirectResponse
    {
        $this->assertCanOperateFulfilment($request, $fulfilment);
        $this->couriers->select($fulfilment, $request->validated('courier_id'), $request->user());

        return redirect()
            ->route('inventory.hardware-fulfilments.show', $fulfilment)
            ->with('status', 'Courier selected.');
    }

    public function storeAwb(AssignHardwareFulfilmentAwbRequest $request, HardwareFulfilment $fulfilment): RedirectResponse
    {
        $this->assertCanOperateFulfilment($request, $fulfilment);
        $this->shipments->assignAwb($fulfilment, $request->user());

        return redirect()
            ->route('inventory.hardware-fulfilments.show', $fulfilment)
            ->with('status', 'AWB assigned.');
    }

    public function storeLabel(GenerateHardwareFulfilmentLabelRequest $request, HardwareFulfilment $fulfilment): RedirectResponse
    {
        $this->assertCanOperateFulfilment($request, $fulfilment);
        $this->documents->generateLabel($fulfilment, $request->user());

        return redirect()
            ->route('inventory.hardware-fulfilments.show', $fulfilment)
            ->with('status', 'Shipping label generated.');
    }

    public function storePickup(RequestHardwareFulfilmentPickupRequest $request, HardwareFulfilment $fulfilment): RedirectResponse
    {
        $this->assertCanOperateFulfilment($request, $fulfilment);
        $this->documents->requestPickup($fulfilment, $request->user());

        return redirect()
            ->route('inventory.hardware-fulfilments.show', $fulfilment)
            ->with('status', 'Pickup requested.');
    }

    public function storeManifest(GenerateHardwareFulfilmentManifestRequest $request, HardwareFulfilment $fulfilment): RedirectResponse
    {
        $this->assertCanOperateFulfilment($request, $fulfilment);
        $this->documents->generateManifest($fulfilment, $request->user());

        return redirect()
            ->route('inventory.hardware-fulfilments.show', $fulfilment)
            ->with('status', 'Manifest generated.');
    }

    public function storePackageEvidence(StoreHardwareFulfilmentPackageEvidenceRequest $request, HardwareFulfilment $fulfilment): RedirectResponse
    {
        $this->assertCanOperateFulfilment($request, $fulfilment);
        $kind = HardwareFulfilmentPackageEvidenceKind::from($request->validated('kind'));
        $this->packageEvidence->attach(
            $fulfilment,
            $kind,
            $request->file('photo'),
            $request->user(),
        );

        return redirect()
            ->route('inventory.hardware-fulfilments.show', $fulfilment)
            ->with('status', $kind->label().' recorded.');
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

    public function storeReadyForPickup(MarkHardwareFulfilmentReadyForPickupRequest $request, HardwareFulfilment $fulfilment): RedirectResponse
    {
        $this->assertCanOperateFulfilment($request, $fulfilment);
        $this->documents->markReadyForPickup($fulfilment, $request->user());

        return redirect()
            ->route('inventory.hardware-fulfilments.show', $fulfilment)
            ->with('status', 'Fulfilment marked ready for pickup.');
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
}
