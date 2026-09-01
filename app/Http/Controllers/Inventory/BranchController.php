<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\InventoryBranch;
use App\Models\User;
use App\Support\Inventory\InventoryAccess;
use App\Support\Inventory\InventoryBranchScope;
use App\Support\Inventory\PosAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
        return view('inventory.branches.create', [
            'assignableUsers' => $this->assignableUsers(),
            'assignedUserIds' => [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $userIds = $data['user_ids'];
        unset($data['user_ids']);
        $data['code'] = strtoupper($data['code']);
        $branch = InventoryBranch::query()->create($data);
        $branch->assignedUsers()->sync($userIds);

        return redirect()->route('inventory.branches.index')->with('status', 'Branch created.');
    }

    public function edit(InventoryBranch $branch): View
    {
        return view('inventory.branches.edit', [
            'branch' => $branch,
            'assignableUsers' => $this->assignableUsers(),
            'assignedUserIds' => $branch->assignedUsers()->pluck('users.id')->all(),
        ]);
    }

    public function update(Request $request, InventoryBranch $branch): RedirectResponse
    {
        $data = $this->validated($request, $branch->id);
        $userIds = $data['user_ids'];
        unset($data['user_ids']);
        $data['code'] = strtoupper($data['code']);
        $branch->update($data);
        $branch->assignedUsers()->sync($userIds);

        return redirect()->route('inventory.branches.index')->with('status', 'Branch updated.');
    }

    /**
     * @return array{code: string, name: string, gstin: ?string, is_active: bool, user_ids: list<int>}
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
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $data['is_active'] = $request->boolean('is_active');
        $data['user_ids'] = array_map('intval', $data['user_ids'] ?? []);

        return $data;
    }

    /**
     * @return Collection<int, User>
     */
    private function assignableUsers()
    {
        return User::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user): bool => InventoryAccess::allows($user) || PosAccess::allows($user))
            ->filter(fn (User $user): bool => ! InventoryBranchScope::canOperateAll($user))
            ->values();
    }
}
