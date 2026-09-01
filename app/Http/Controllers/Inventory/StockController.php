<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventoryProductVariant;
use App\Models\InventoryStockBalance;
use App\Services\Inventory\InventoryStockService;
use App\Support\Inventory\InventoryAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StockController extends Controller
{
    public function __construct(
        private readonly InventoryStockService $stock,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless(InventoryAccess::allows($request->user()), 403);

            return $next($request);
        });
    }

    public function index(Request $request): View
    {
        $balances = InventoryStockBalance::query()
            ->with(['product', 'variant', 'branch'])
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('product_id'), fn ($q) => $q->where('product_id', $request->integer('product_id')))
            ->orderByDesc('available_qty')
            ->paginate(40)
            ->withQueryString();

        return view('inventory.stock.index', [
            'balances' => $balances,
            'branches' => InventoryBranch::query()->where('is_active', true)->orderBy('name')->get(),
            'products' => InventoryProduct::query()->where('is_active', true)->orderBy('name')->get(),
            'filters' => $request->only(['branch_id', 'product_id']),
            'canStockIn' => InventoryAccess::allowsPermission($request->user(), RolePermissionSeeder::PERMISSION_INVENTORY_STOCK_IN),
            'canAdjust' => InventoryAccess::allowsPermission($request->user(), RolePermissionSeeder::PERMISSION_INVENTORY_STOCK_ADJUST),
            'canReserve' => InventoryAccess::allowsPermission($request->user(), RolePermissionSeeder::PERMISSION_INVENTORY_STOCK_RESERVE),
        ]);
    }

    public function create(Request $request): View
    {
        abort_unless(
            InventoryAccess::allowsPermission($request->user(), RolePermissionSeeder::PERMISSION_INVENTORY_STOCK_IN),
            403,
        );

        return view('inventory.stock.create', [
            'branches' => InventoryBranch::query()->where('is_active', true)->orderBy('name')->get(),
            'products' => InventoryProduct::query()->with('variants')->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(
            InventoryAccess::allowsPermission($request->user(), RolePermissionSeeder::PERMISSION_INVENTORY_STOCK_IN),
            403,
        );

        $data = $request->validate([
            'branch_id' => ['required', 'exists:inventory_branches,id'],
            'product_id' => ['required', 'exists:inventory_products,id'],
            'variant_id' => ['nullable', 'exists:inventory_product_variants,id'],
            'qty' => ['nullable', 'integer', 'min:1'],
            'serials' => ['nullable', 'string'],
            'batch_code' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $branch = InventoryBranch::query()->findOrFail($data['branch_id']);
        $product = InventoryProduct::query()->findOrFail($data['product_id']);
        $variant = ! empty($data['variant_id'])
            ? InventoryProductVariant::query()->findOrFail($data['variant_id'])
            : null;

        if ($product->is_serialized) {
            $this->stock->stockInSerialized(
                product: $product,
                branch: $branch,
                serials: $data['serials'] ?? '',
                actor: $request->user(),
                variant: $variant,
                batchCode: $data['batch_code'] ?? null,
                notes: $data['notes'] ?? null,
            );
        } else {
            $this->stock->stockInQuantity(
                product: $product,
                branch: $branch,
                qty: (int) ($data['qty'] ?? 0),
                actor: $request->user(),
                variant: $variant,
                notes: $data['notes'] ?? null,
            );
        }

        return redirect()->route('inventory.stock.index')->with('status', 'Stock received.');
    }
}
