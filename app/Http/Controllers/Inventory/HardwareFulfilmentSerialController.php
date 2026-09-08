<?php

namespace App\Http\Controllers\Inventory;

use App\Enums\HardwareFulfilmentState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\AllocateHardwareFulfilmentSerialsRequest;
use App\Http\Requests\Inventory\AssignHardwareFulfilmentAwbRequest;
use App\Http\Requests\Inventory\AttachHardwareFulfilmentParcelRequest;
use App\Http\Requests\Inventory\CorrectHardwareFulfilmentShippingCountryRequest;
use App\Http\Requests\Inventory\CreateHardwareFulfilmentShipmentRequest;
use App\Http\Requests\Inventory\FetchHardwareFulfilmentCourierOptionsRequest;
use App\Http\Requests\Inventory\SearchHardwareFulfilmentSerialsRequest;
use App\Http\Requests\Inventory\SelectHardwareFulfilmentCourierRequest;
use App\Models\HardwareFulfilment;
use App\Models\InventoryBranch;
use App\Services\HardwareFulfilment\HardwareFulfilmentCountryCorrectionService;
use App\Services\HardwareFulfilment\HardwareFulfilmentEligibility;
use App\Services\HardwareFulfilment\HardwareFulfilmentParcelSnapshotService;
use App\Services\HardwareFulfilment\HardwareSerialAllocationService;
use App\Services\HardwareFulfilment\HardwareShipmentCourierOptionsService;
use App\Services\HardwareFulfilment\HardwareShipmentEligibility;
use App\Services\HardwareFulfilment\HardwareShipmentService;
use App\Support\HardwareFulfilment\HardwareFulfilmentAccess;
use App\Support\Inventory\InventoryBranchScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class HardwareFulfilmentSerialController extends Controller
{
    public function __construct(
        private readonly HardwareSerialAllocationService $allocation,
        private readonly HardwareShipmentService $shipments,
        private readonly HardwareShipmentEligibility $shipmentEligibility,
        private readonly HardwareFulfilmentParcelSnapshotService $snapshots,
        private readonly HardwareFulfilmentCountryCorrectionService $countries,
        private readonly HardwareShipmentCourierOptionsService $couriers,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless(HardwareFulfilmentAccess::allows($request->user()), 403);

            return $next($request);
        });
    }

    public function index(Request $request): View
    {
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
            'fulfilments' => $fulfilments,
            'branches' => InventoryBranch::query()->where('is_active', true)->orderBy('code')->get(),
            'filters' => [
                'order' => $order,
                'serial' => $serial,
                'branch_id' => $branchId,
                'shipment_status' => $shipmentStatus,
                'state' => $state,
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
        ]);

        $requirements = $this->allocation->requirements($fulfilment);
        $canAllocate = $fulfilment->state === HardwareFulfilmentState::ReadyForFulfilment
            && ! HardwareFulfilmentEligibility::isFrozenSourceId((string) $fulfilment->source_id)
            && collect($requirements)->every(fn (array $line): bool => $line['map_ready']);

        return view('inventory.hardware-fulfilments.show', [
            'fulfilment' => $fulfilment,
            'requirements' => $requirements,
            'allocated' => $fulfilment->serials,
            'canAllocate' => $canAllocate,
            'derivedBranch' => $fulfilment->fulfilmentBranch,
            'shipment' => $this->shipmentEligibility->inspect($fulfilment),
            'canCorrectCountry' => HardwareFulfilmentAccess::allowsCountryCorrection($request->user()),
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
