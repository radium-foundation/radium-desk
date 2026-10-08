<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\InterBranchTransaction;
use App\Models\InventoryProduct;
use App\Services\Inventory\InterBranchTransferService;
use App\Support\Inventory\InventoryAccess;
use App\Support\Inventory\InventoryBranchScope;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class InterBranchTransferController extends Controller
{
    public function __construct(
        private readonly InterBranchTransferService $interBranch,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless(InventoryAccess::allows($request->user()), 403);

            return $next($request);
        });
    }

    public function index(Request $request): View
    {
        $user = $request->user();

        $query = InterBranchTransaction::query()
            ->with(['fromBranch', 'toBranch', 'statutoryInvoice'])
            ->latest('id');

        $allowed = InventoryBranchScope::allowedBranchIds($user);
        if ($allowed !== null) {
            $query->where(function ($builder) use ($allowed): void {
                $builder->whereIn('from_branch_id', $allowed)
                    ->orWhereIn('to_branch_id', $allowed);
            });
        }

        return view('inventory.inter-branch-transfers.index', [
            'transactions' => $query->paginate(30),
            'canCreate' => InventoryAccess::allowsPermission(
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

        return view('inventory.inter-branch-transfers.create', [
            'branches' => InventoryBranchScope::allowedBranches($request->user()),
            'products' => InventoryProduct::query()->where('is_active', true)->orderBy('name')->get(),
            'idempotencyKey' => (string) Str::uuid(),
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
            'idempotency_key' => ['required', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'exists:inventory_products,id'],
            'lines.*.variant_id' => ['nullable', 'exists:inventory_product_variants,id'],
            'lines.*.qty' => ['required', 'integer', 'min:1'],
            'lines.*.serials' => ['nullable', 'string'],
        ]);

        $from = InventoryBranchScope::requireBranchId($data['from_branch_id'], $request->user(), 'from_branch_id');
        $to = InventoryBranchScope::requireBranchId($data['to_branch_id'], $request->user(), 'to_branch_id');
        InventoryBranchScope::assertCanTransfer($request->user(), $from, $to);

        $transaction = $this->interBranch->issue(
            from: $from,
            to: $to,
            lines: $data['lines'],
            actor: $request->user(),
            idempotencyKey: $data['idempotency_key'],
            notes: $data['notes'] ?? null,
        );

        return redirect()
            ->route('inventory.inter-branch-transfers.show', $transaction)
            ->with('status', 'Inter-branch transfer issued. Review dispatch details before shipping stock.');
    }

    public function show(Request $request, InterBranchTransaction $interBranchTransfer): View
    {
        $interBranchTransfer->load([
            'fromBranch',
            'toBranch',
            'lines.product',
            'lines.serial',
            'statutoryInvoice.items',
            'inventoryTransfer.lines',
            'createdBy',
        ]);

        $user = $request->user();
        if (
            ! InventoryBranchScope::allows($user, $interBranchTransfer->fromBranch)
            && ! InventoryBranchScope::allows($user, $interBranchTransfer->toBranch)
        ) {
            abort(403, 'You cannot view this inter-branch transfer.');
        }

        return view('inventory.inter-branch-transfers.show', [
            'transaction' => $interBranchTransfer,
            'canDispatch' => InventoryAccess::allowsPermission($user, RolePermissionSeeder::PERMISSION_INVENTORY_STOCK_TRANSFER)
                && $interBranchTransfer->status->canDispatch()
                && InventoryBranchScope::allows($user, $interBranchTransfer->fromBranch),
            'canReceive' => InventoryAccess::allowsPermission($user, RolePermissionSeeder::PERMISSION_INVENTORY_STOCK_TRANSFER)
                && $interBranchTransfer->status->canReceive()
                && InventoryBranchScope::allows($user, $interBranchTransfer->toBranch),
            'canCancel' => InventoryAccess::allowsPermission($user, RolePermissionSeeder::PERMISSION_INVENTORY_STOCK_TRANSFER)
                && $interBranchTransfer->status->canCancel(),
        ]);
    }

    public function dispatch(Request $request, InterBranchTransaction $interBranchTransfer): RedirectResponse
    {
        abort_unless(
            InventoryAccess::allowsPermission($request->user(), RolePermissionSeeder::PERMISSION_INVENTORY_STOCK_TRANSFER),
            403,
        );

        $data = $request->validate([
            'transporter' => ['nullable', 'string', 'max:200'],
            'transport_reference' => ['nullable', 'string', 'max:200'],
            'dispatch_date' => ['nullable', 'date'],
            'eway_bill_reference' => ['nullable', 'string', 'max:100'],
            'eway_bill_notes' => ['nullable', 'string', 'max:500'],
        ]);

        InventoryBranchScope::assertCanTransfer(
            $request->user(),
            $interBranchTransfer->fromBranch,
            $interBranchTransfer->toBranch,
        );

        $this->interBranch->dispatch($interBranchTransfer, $request->user(), $data);

        return back()->with('status', 'Stock dispatched and marked in transit.');
    }

    public function receive(Request $request, InterBranchTransaction $interBranchTransfer): RedirectResponse
    {
        abort_unless(
            InventoryAccess::allowsPermission($request->user(), RolePermissionSeeder::PERMISSION_INVENTORY_STOCK_TRANSFER),
            403,
        );

        InventoryBranchScope::assertCanTransfer(
            $request->user(),
            $interBranchTransfer->fromBranch,
            $interBranchTransfer->toBranch,
        );

        $this->interBranch->receive($interBranchTransfer, $request->user());

        return back()->with('status', 'Stock received at destination branch.');
    }

    public function cancel(Request $request, InterBranchTransaction $interBranchTransfer): RedirectResponse
    {
        abort_unless(
            InventoryAccess::allowsPermission($request->user(), RolePermissionSeeder::PERMISSION_INVENTORY_STOCK_TRANSFER),
            403,
        );

        $data = $request->validate([
            'cancel_reason' => ['required', 'string', 'max:500'],
        ]);

        InventoryBranchScope::assertCanTransfer(
            $request->user(),
            $interBranchTransfer->fromBranch,
            $interBranchTransfer->toBranch,
        );

        $this->interBranch->cancel($interBranchTransfer, $request->user(), $data['cancel_reason']);

        return back()->with('status', 'Inter-branch transfer cancelled.');
    }
}
