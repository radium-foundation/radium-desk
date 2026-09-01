<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\InventoryBranch;
use App\Models\InventoryReservation;
use App\Services\Inventory\InventoryStockService;
use App\Support\Inventory\InventoryAccess;
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

    public function index(): View
    {
        return view('inventory.reservations.index', [
            'reservations' => InventoryReservation::query()
                ->with(['branch', 'createdBy', 'sale'])
                ->latest('id')
                ->paginate(30),
        ]);
    }

    public function create(): View
    {
        return view('inventory.reservations.create', [
            'branches' => InventoryBranch::query()->where('is_active', true)->orderBy('name')->get(),
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
            branch: InventoryBranch::query()->findOrFail($data['branch_id']),
            serials: $data['serials'],
            actor: $request->user(),
            notes: $data['notes'] ?? null,
        );

        return redirect()->route('inventory.reservations.index')
            ->with('status', 'Reservation '.$reservation->reservation_no.' created.');
    }

    public function release(Request $request, InventoryReservation $reservation): RedirectResponse
    {
        $this->stock->releaseReservation($reservation, $request->user(), $request->string('notes')->toString() ?: null);

        return redirect()->route('inventory.reservations.index')->with('status', 'Reservation released.');
    }
}
