<?php

namespace App\Http\Controllers\Inventory;

use App\Enums\HardwareFulfilmentState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\AllocateHardwareFulfilmentSerialsRequest;
use App\Http\Requests\Inventory\CreateHardwareFulfilmentShipmentRequest;
use App\Http\Requests\Inventory\SearchHardwareFulfilmentSerialsRequest;
use App\Models\HardwareFulfilment;
use App\Models\InventoryBranch;
use App\Services\HardwareFulfilment\HardwareFulfilmentEligibility;
use App\Services\HardwareFulfilment\HardwareSerialAllocationService;
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
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless(HardwareFulfilmentAccess::allows($request->user()), 403);

            return $next($request);
        });
    }

    public function index(Request $request): View
    {
        $fulfilments = HardwareFulfilment::query()
            ->with(['commerceOrder', 'fulfilmentBranch'])
            ->whereIn('state', [
                HardwareFulfilmentState::Ingested,
                HardwareFulfilmentState::ReadyForFulfilment,
                HardwareFulfilmentState::SerialsAllocated,
                HardwareFulfilmentState::InvoiceIssued,
                HardwareFulfilmentState::ShipmentCreated,
                HardwareFulfilmentState::AwbAssigned,
            ])
            ->orderByDesc('id')
            ->paginate(40)
            ->withQueryString();

        return view('inventory.hardware-fulfilments.index', [
            'fulfilments' => $fulfilments,
        ]);
    }

    public function show(Request $request, HardwareFulfilment $fulfilment): View
    {
        $this->assertCanOperateFulfilment($request, $fulfilment);
        $fulfilment->load([
            'commerceOrder.items',
            'serials.inventorySerial.branch',
            'fulfilmentBranch',
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

    public function storeAwb(Request $request, HardwareFulfilment $fulfilment): RedirectResponse
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
