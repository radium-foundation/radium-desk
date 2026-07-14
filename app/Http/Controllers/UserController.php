<?php

namespace App\Http\Controllers;

use App\Http\Requests\ResetUserPasswordRequest;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Requests\UpdateUserStatusRequest;
use App\Models\User;
use App\Services\Operations\OperationsRoleService;
use App\Services\Operations\WorkforceAuthorityService;
use App\Services\UserManagementService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UserController extends Controller
{
    public function __construct(
        private readonly UserManagementService $userManagementService,
    ) {
        $this->authorizeResource(User::class, 'user', [
            'except' => ['updateStatus', 'resetPassword'],
        ]);
    }

    public function index(Request $request): View
    {
        $search = $request->string('q')->trim()->toString();

        $users = User::query()
            ->with('roles')
            ->withCount('assignedIncidents')
            ->when($request->filled('role'), function (Builder $query) use ($request) {
                $query->role($request->string('role')->trim()->toString());
            })
            ->when($request->filled('status'), function (Builder $query) use ($request) {
                $status = $request->string('status')->trim()->toString();

                if ($status === 'active') {
                    $query->where('is_active', true);
                }

                if ($status === 'inactive') {
                    $query->where('is_active', false);
                }
            })
            ->when($search !== '', function (Builder $query) use ($search) {
                $query->where(function (Builder $builder) use ($search) {
                    $builder->where('first_name', 'like', '%'.$search.'%')
                        ->orWhere('last_name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%')
                        ->orWhere('name', 'like', '%'.$search.'%');
                });
            })
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->paginate(15)
            ->withQueryString();

        $operationsRoleService = app(OperationsRoleService::class);
        $workforceAuthority = app(WorkforceAuthorityService::class);

        $roleOptions = collect($operationsRoleService->operationalRoleSlugs())
            ->map(fn (string $slug): array => [
                'slug' => $slug,
                'label' => $operationsRoleService->displayLabel($slug),
            ])
            ->all();

        $workforceSnapshots = [];
        foreach ($users as $user) {
            $workforceSnapshots[$user->id] = $workforceAuthority->snapshotFor($user);
        }

        return view('users.index', [
            'users' => $users,
            'filters' => $request->only(['role', 'status', 'q']),
            'roleOptions' => $roleOptions,
            'workforceSnapshots' => $workforceSnapshots,
        ]);
    }

    public function create(): View
    {
        /** @var User $actor */
        $actor = auth()->user();

        return view('users.create', [
            'user' => new User(['is_active' => true]),
            'roleOptions' => $this->assignableRoleOptions($actor),
            'permissionGroups' => $this->userManagementService->assignablePermissionGroups(),
            'currentPermissions' => [],
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $this->userManagementService->createUser([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'] ?? '',
            'email' => $validated['email'],
            'password' => $validated['password'],
            'roles' => $validated['roles'],
            'is_active' => $validated['is_active'] ?? true,
            'bonvoice_extension' => $validated['bonvoice_extension'] ?? null,
            'permissions' => $validated['permissions'] ?? [],
        ], $request->user());

        return redirect()
            ->route('users.index')
            ->with('status', 'user-created');
    }

    public function edit(User $user): View
    {
        $user->load(['roles', 'permissions']);

        /** @var User $actor */
        $actor = auth()->user();

        $operationsRoleService = app(\App\Services\Operations\OperationsRoleService::class);
        $workSchedule = app(\App\Services\Operations\TeamWorkScheduleService::class)->snapshotFor($user);

        return view('users.edit', [
            'user' => $user,
            'roleOptions' => $this->assignableRoleOptions($actor),
            'currentRoles' => $user->roles->pluck('name')->all(),
            'permissionGroups' => $this->userManagementService->assignablePermissionGroups(),
            'currentPermissions' => $this->userManagementService->directPermissionNamesFromUser($user),
            'showsWorkSchedule' => $operationsRoleService->isTeamMember($user)
                && $actor->can('workforce-calendar.manage'),
            'workSchedule' => $workSchedule,
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $validated = $request->validated();

        $this->userManagementService->updateUser($user, [
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'] ?? '',
            'email' => $validated['email'],
            'roles' => $validated['roles'],
            'is_active' => $validated['is_active'],
            'bonvoice_extension' => $validated['bonvoice_extension'] ?? null,
            'permissions' => $validated['permissions'] ?? [],
        ], $request->user());

        return redirect()
            ->route('users.edit', $user)
            ->with('status', 'user-updated');
    }

    public function updateStatus(UpdateUserStatusRequest $request, User $user): RedirectResponse
    {
        $this->authorize('updateStatus', $user);

        $isActive = $request->boolean('is_active');

        $this->userManagementService->updateStatus($user, $isActive, $request->user());

        return redirect()
            ->route('users.edit', $user)
            ->with('status', $isActive ? 'user-activated' : 'user-deactivated');
    }

    public function resetPassword(ResetUserPasswordRequest $request, User $user): RedirectResponse
    {
        $this->authorize('resetPassword', $user);

        $this->userManagementService->resetPassword(
            $user,
            $request->validated('password'),
            $request->user(),
        );

        return redirect()
            ->route('users.edit', $user)
            ->with('status', 'user-password-reset');
    }

    public function destroy(User $user): RedirectResponse
    {
        $this->authorize('delete', $user);

        $this->userManagementService->deleteUser($user, auth()->user());

        return redirect()
            ->route('users.index')
            ->with('status', 'user-deleted');
    }

    /**
     * @return list<array{slug: string, label: string}>
     */
    private function assignableRoleOptions(User $actor): array
    {
        $operationsRoleService = app(OperationsRoleService::class);

        return collect($this->userManagementService->assignableRoles($actor))
            ->map(fn (string $slug): array => [
                'slug' => $slug,
                'label' => $operationsRoleService->displayLabel($slug),
            ])
            ->all();
    }
}
