<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\InventoryReservation;
use App\Services\Inventory\InventoryStockService;
use App\Support\Inventory\InventoryAccess;
use App\Support\Inventory\InventoryBranchScope;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReservationController extends Controller
{
    public function __construct(
        private readonly InventoryStockService $stock,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless(
                InventoryAccess::allowsPermission(
                    $request->user(),
                    RolePermissionSeeder::PERMISSION_INVENTORY_STOCK_RESERVE,
                ),
                403,
            );

            return $next($request);
        });
    }

    public function index(Request $request): View
    {
        $user = $request->user();

        return view('inventory.reservations.index', [
            'reservations' => InventoryBranchScope::constrain(
                InventoryReservation::query()->with(['branch', 'createdBy', 'sale']),
                $user,
            )
                ->latest('id')
                ->paginate(30),
            'needsBranchAssignment' => InventoryBranchScope::needsAssignment($user),
        ]);
    }

    public function create(Request $request): View
    {
        return view('inventory.reservations.create', [
            'branches' => InventoryBranchScope::allowedBranches($request->user()),
            'needsBranchAssignment' => InventoryBranchScope::needsAssignment($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'exists:inventory_branches,id'],
            'serials' => ['required', 'string'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $reservation = $this->stock->reserveSerials(
            branch: InventoryBranchScope::requireBranchId($data['branch_id'], $request->user()),
            serials: $data['serials'],
            actor: $request->user(),
            notes: $data['notes'] ?? null,
        );

        return redirect()->route('inventory.reservations.index')
            ->with('status', 'Reservation '.$reservation->reservation_no.' created.');
    }

    public function release(Request $request, InventoryReservation $reservation): RedirectResponse
    {
        $reservation->load('branch');
        InventoryBranchScope::assertCanOperate($request->user(), $reservation->branch);
        $this->stock->releaseReservation($reservation, $request->user(), $request->string('notes')->toString() ?: null);

        return redirect()->route('inventory.reservations.index')->with('status', 'Reservation released.');
    }
}
