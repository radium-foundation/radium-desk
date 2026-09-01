<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\InventoryBranch;
use App\Support\Inventory\InventoryAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BranchController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            abort_unless(
                InventoryAccess::allowsPermission(
                    $request->user(),
                    RolePermissionSeeder::PERMISSION_INVENTORY_BRANCHES_MANAGE,
                ),
                403,
            );

            return $next($request);
        });
    }

    public function index(): View
    {
        return view('inventory.branches.index', [
            'branches' => InventoryBranch::query()->orderBy('name')->paginate(30),
        ]);
    }

    public function create(): View
    {
        return view('inventory.branches.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['code'] = strtoupper($data['code']);
        InventoryBranch::query()->create($data);

        return redirect()->route('inventory.branches.index')->with('status', 'Branch created.');
    }

    public function edit(InventoryBranch $branch): View
    {
        return view('inventory.branches.edit', ['branch' => $branch]);
    }

    public function update(Request $request, InventoryBranch $branch): RedirectResponse
    {
        $data = $this->validated($request, $branch->id);
        $data['code'] = strtoupper($data['code']);
        $branch->update($data);

        return redirect()->route('inventory.branches.index')->with('status', 'Branch updated.');
    }

    /**
     * @return array{code: string, name: string, gstin: ?string, is_active: bool}
     */
    private function validated(Request $request, ?int $ignoreId = null): array
    {
        $unique = 'unique:inventory_branches,code';
        if ($ignoreId !== null) {
            $unique .= ','.$ignoreId;
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', $unique],
            'name' => ['required', 'string', 'max:120'],
            'gstin' => ['nullable', 'string', 'max:32'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
