<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\InventoryProduct;
use App\Models\InventoryProductVariant;
use App\Models\InventoryTransfer;
use App\Services\Inventory\InventoryStockService;
use App\Support\Inventory\InventoryAccess;
use App\Support\Inventory\InventoryBranchScope;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TransferController extends Controller
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
        $user = $request->user();

        return view('inventory.transfers.index', [
            'transfers' => InventoryBranchScope::constrainTransfer(
                InventoryTransfer::query()->with(['fromBranch', 'toBranch', 'createdBy']),
                $user,
            )
                ->latest('id')
                ->paginate(30),
            'canTransfer' => InventoryAccess::allowsPermission(
                $user,
                RolePermissionSeeder::PERMISSION_INVENTORY_STOCK_TRANSFER,
            ),
            'needsBranchAssignment' => InventoryBranchScope::needsAssignment($user),
        ]);
    }

    public function create(Request $request): View
    {
        abort_unless(
            InventoryAccess::allowsPermission($request->user(), RolePermissionSeeder::PERMISSION_INVENTORY_STOCK_TRANSFER),
            403,
        );

        return view('inventory.transfers.create', [
            'branches' => InventoryBranchScope::allowedBranches($request->user()),
            'products' => InventoryProduct::query()->with('variants')->where('is_active', true)->orderBy('name')->get(),
            'needsBranchAssignment' => InventoryBranchScope::needsAssignment($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(
            InventoryAccess::allowsPermission($request->user(), RolePermissionSeeder::PERMISSION_INVENTORY_STOCK_TRANSFER),
            403,
        );

        $data = $request->validate([
            'from_branch_id' => ['required', 'exists:inventory_branches,id'],
            'to_branch_id' => ['required', 'exists:inventory_branches,id', 'different:from_branch_id'],
            'mode' => ['required', 'in:serial,quantity'],
            'serials' => ['nullable', 'string'],
            'product_id' => ['nullable', 'exists:inventory_products,id'],
            'variant_id' => ['nullable', 'exists:inventory_product_variants,id'],
            'qty' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $from = InventoryBranchScope::requireBranchId($data['from_branch_id'], $request->user(), 'from_branch_id');
        $to = InventoryBranchScope::requireBranchId($data['to_branch_id'], $request->user(), 'to_branch_id');
        InventoryBranchScope::assertCanTransfer($request->user(), $from, $to);

        if ($data['mode'] === 'serial') {
            $transfer = $this->stock->transferSerials(
                from: $from,
                to: $to,
                serials: $data['serials'] ?? '',
                actor: $request->user(),
                notes: $data['notes'] ?? null,
            );
        } else {
            $product = InventoryProduct::query()->findOrFail($data['product_id'] ?? 0);
            $variant = ! empty($data['variant_id'])
                ? InventoryProductVariant::query()->findOrFail($data['variant_id'])
                : null;
            $transfer = $this->stock->transferQuantity(
                product: $product,
                from: $from,
                to: $to,
                qty: (int) ($data['qty'] ?? 0),
                actor: $request->user(),
                variant: $variant,
                notes: $data['notes'] ?? null,
            );
        }

        return redirect()->route('inventory.transfers.show', $transfer)->with('status', 'Transfer completed.');
    }

    public function show(Request $request, InventoryTransfer $transfer): View
    {
        $transfer->load(['fromBranch', 'toBranch', 'createdBy', 'lines.product', 'lines.serial']);

        $user = $request->user();
        if (
            ! InventoryBranchScope::allows($user, $transfer->fromBranch)
            && ! InventoryBranchScope::allows($user, $transfer->toBranch)
        ) {
            abort(403, 'You cannot view this transfer.');
        }

        return view('inventory.transfers.show', ['transfer' => $transfer]);
    }
}
