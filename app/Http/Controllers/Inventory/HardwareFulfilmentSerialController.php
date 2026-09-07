<?php

namespace App\Http\Controllers\Inventory;

use App\Enums\HardwareFulfilmentState;
use App\Http\Controllers\Controller;
use App\Models\HardwareFulfilment;
use App\Models\InventoryBranch;
use App\Services\HardwareFulfilment\HardwareSerialAllocationService;
use App\Support\HardwareFulfilment\HardwareFulfilmentAccess;
use App\Support\Inventory\InventoryBranchScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class HardwareFulfilmentSerialController extends Controller
{
    public function __construct(
        private readonly HardwareSerialAllocationService $allocation,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless(HardwareFulfilmentAccess::allows($request->user()), 403);

            return $next($request);
        });
    }

    public function index(Request $request): View
    {
        $fulfilments = HardwareFulfilment::query()
            ->with('commerceOrder')
            ->whereIn('state', [
                HardwareFulfilmentState::Ingested,
                HardwareFulfilmentState::ReadyForFulfilment,
                HardwareFulfilmentState::SerialsAllocated,
            ])
            ->orderByDesc('id')
            ->paginate(40)
            ->withQueryString();

        return view('inventory.hardware-fulfilments.index', [
            'fulfilments' => $fulfilments,
            'branches' => $this->branchIndex(InventoryBranchScope::allowedBranches($request->user())),
        ]);
    }

    public function show(Request $request, HardwareFulfilment $fulfilment): View
    {
        $this->assertCanOperateFulfilment($request, $fulfilment);
        $fulfilment->load(['commerceOrder.items', 'serials']);

        return view('inventory.hardware-fulfilments.show', [
            'fulfilment' => $fulfilment,
            'requirements' => $this->allocation->requirements($fulfilment),
            'allocated' => $fulfilment->serials,
            'branches' => InventoryBranchScope::allowedBranches($request->user()),
        ]);
    }

    public function search(Request $request, HardwareFulfilment $fulfilment): JsonResponse
    {
        $this->assertCanOperateFulfilment($request, $fulfilment);

        return response()->json([
            'serials' => $this->allocation->searchAvailable(
                $fulfilment,
                $request->integer('commerce_order_item_id'),
                $request->string('q')->toString(),
            ),
        ]);
    }

    public function store(Request $request, HardwareFulfilment $fulfilment): RedirectResponse
    {
        $this->assertCanOperateFulfilment($request, $fulfilment);

        $validated = $request->validate([
            'serials' => ['required', 'array'],
            'serials.*' => ['array'],
            'serials.*.*' => ['string'],
        ]);

        $this->allocation->allocate($fulfilment, $validated['serials'], $request->user());

        return redirect()
            ->route('inventory.hardware-fulfilments.show', $fulfilment)
            ->with('status', 'Serials allocated.');
    }

    /**
     * @param  Collection<int, InventoryBranch>  $branches
     * @return array<int, InventoryBranch>
     */
    private function branchIndex($branches): array
    {
        return $branches->keyBy('id')->all();
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
