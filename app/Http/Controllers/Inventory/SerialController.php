<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\InventorySerial;
use App\Support\Inventory\InventoryAccess;
use App\Support\Inventory\InventoryBranchScope;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SerialController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            abort_unless(InventoryAccess::allows($request->user()), 403);

            return $next($request);
        });
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        $search = $request->string('q')->trim()->toString();

        $serials = InventoryBranchScope::constrain(
            InventorySerial::query()->with(['product', 'variant', 'branch']),
            $user,
        )
            ->when($search !== '', fn ($q) => $q->where('serial_number', 'like', '%'.$search.'%'))
            ->when($request->filled('branch_id'), function ($q) use ($request, $user) {
                $branch = InventoryBranchScope::requireBranchId($request->integer('branch_id'), $user);
                $q->where('branch_id', $branch->id);
            })
            ->when($request->filled('product_id'), fn ($q) => $q->where('product_id', $request->integer('product_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest('id')
            ->paginate(40)
            ->withQueryString();

        return view('inventory.serials.index', [
            'serials' => $serials,
            'branches' => InventoryBranchScope::allowedBranches($user, activeOnly: false),
            'products' => InventoryProduct::query()->where('is_serialized', true)->orderBy('name')->get(),
            'filters' => $request->only(['q', 'branch_id', 'product_id', 'status']),
            'needsBranchAssignment' => InventoryBranchScope::needsAssignment($user),
        ]);
    }

    public function show(Request $request, InventorySerial $serial): View
    {
        $serial->load(['product', 'variant', 'branch']);
        InventoryBranchScope::assertCanOperate($request->user(), $serial->branch);

        $movements = InventoryMovement::query()
            ->with(['actor', 'fromBranch', 'toBranch', 'sale'])
            ->where('serial_id', $serial->id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get();

        return view('inventory.serials.show', [
            'serial' => $serial,
            'movements' => $movements,
        ]);
    }
}
