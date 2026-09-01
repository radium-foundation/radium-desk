<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\InventoryBranch;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\InventorySerial;
use App\Support\Inventory\InventoryAccess;
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
        $search = $request->string('q')->trim()->toString();

        $serials = InventorySerial::query()
            ->with(['product', 'variant', 'branch'])
            ->when($search !== '', fn ($q) => $q->where('serial_number', 'like', '%'.$search.'%'))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('product_id'), fn ($q) => $q->where('product_id', $request->integer('product_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest('id')
            ->paginate(40)
            ->withQueryString();

        return view('inventory.serials.index', [
            'serials' => $serials,
            'branches' => InventoryBranch::query()->orderBy('name')->get(),
            'products' => InventoryProduct::query()->where('is_serialized', true)->orderBy('name')->get(),
            'filters' => $request->only(['q', 'branch_id', 'product_id', 'status']),
        ]);
    }

    public function show(InventorySerial $serial): View
    {
        $serial->load(['product', 'variant', 'branch']);
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
