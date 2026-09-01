<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\InventoryBranch;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Support\Inventory\InventoryAccess;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MovementController extends Controller
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
        $movements = InventoryMovement::query()
            ->with(['product', 'serial', 'branch', 'fromBranch', 'toBranch', 'actor', 'sale'])
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('product_id'), fn ($q) => $q->where('product_id', $request->integer('product_id')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('serial'), fn ($q) => $q->whereHas(
                'serial',
                fn ($serial) => $serial->where('serial_number', 'like', '%'.$request->string('serial')->trim().'%'),
            ))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('inventory.movements.index', [
            'movements' => $movements,
            'branches' => InventoryBranch::query()->orderBy('name')->get(),
            'products' => InventoryProduct::query()->orderBy('name')->get(),
            'filters' => $request->only(['branch_id', 'product_id', 'type', 'serial']),
        ]);
    }
}
