<?php

namespace App\Http\Controllers\Inventory;

use App\Enums\InventoryAdjustmentReason;
use App\Enums\InventorySerialStatus;
use App\Http\Controllers\Controller;
use App\Models\InventoryAdjustment;
use App\Models\InventoryProduct;
use App\Models\InventoryProductVariant;
use App\Services\Inventory\InventoryStockService;
use App\Support\Inventory\InventoryAccess;
use App\Support\Inventory\InventoryBranchScope;
use App\Support\Inventory\InventorySerialNumber;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdjustmentController extends Controller
{
    public function __construct(
        private readonly InventoryStockService $stock,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless(
                InventoryAccess::allowsPermission(
                    $request->user(),
                    RolePermissionSeeder::PERMISSION_INVENTORY_STOCK_ADJUST,
                ),
                403,
            );

            return $next($request);
        });
    }

    public function index(Request $request): View
    {
        $user = $request->user();

        return view('inventory.adjustments.index', [
            'adjustments' => InventoryBranchScope::constrain(
                InventoryAdjustment::query()->with(['branch', 'createdBy']),
                $user,
            )
                ->latest('id')
                ->paginate(30),
            'needsBranchAssignment' => InventoryBranchScope::needsAssignment($user),
        ]);
    }

    public function create(Request $request): View
    {
        return view('inventory.adjustments.create', [
            'branches' => InventoryBranchScope::allowedBranches($request->user()),
            'products' => InventoryProduct::query()->where('is_active', true)->orderBy('name')->get(),
            'reasons' => InventoryAdjustmentReason::cases(),
            'statuses' => [
                InventorySerialStatus::Available,
                InventorySerialStatus::Damaged,
                InventorySerialStatus::Returned,
            ],
            'needsBranchAssignment' => InventoryBranchScope::needsAssignment($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'mode' => ['required', 'in:serial,quantity'],
            'reason' => ['required', 'string'],
            'serial_number' => ['nullable', 'string'],
            'to_status' => ['nullable', 'string'],
            'branch_id' => ['nullable', 'exists:inventory_branches,id'],
            'product_id' => ['nullable', 'exists:inventory_products,id'],
            'variant_id' => ['nullable', 'exists:inventory_product_variants,id'],
            'qty_delta' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $reason = InventoryAdjustmentReason::from($data['reason']);

        if ($data['mode'] === 'serial') {
            $serial = $this->stock->lockSerialByNumber(
                InventorySerialNumber::normalize((string) ($data['serial_number'] ?? '')),
            );
            InventoryBranchScope::assertCanOperate($request->user(), $serial->branch);
            $toStatus = InventorySerialStatus::from((string) $data['to_status']);
            $adjustment = $this->stock->adjustSerialStatus(
                serial: $serial,
                toStatus: $toStatus,
                reason: $reason,
                actor: $request->user(),
                notes: $data['notes'] ?? null,
            );
        } else {
            $branch = InventoryBranchScope::requireBranchId($data['branch_id'] ?? 0, $request->user());
            $adjustment = $this->stock->adjustQuantity(
                product: InventoryProduct::query()->findOrFail($data['product_id'] ?? 0),
                branch: $branch,
                qtyDelta: (int) ($data['qty_delta'] ?? 0),
                reason: $reason,
                actor: $request->user(),
                variant: ! empty($data['variant_id'])
                    ? InventoryProductVariant::query()->findOrFail($data['variant_id'])
                    : null,
                notes: $data['notes'] ?? null,
            );
        }

        return redirect()->route('inventory.adjustments.index')->with('status', 'Adjustment '.$adjustment->adjustment_no.' recorded.');
    }
}
