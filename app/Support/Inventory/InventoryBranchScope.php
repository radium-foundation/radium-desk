<?php

namespace App\Support\Inventory;

use App\Models\InventoryBranch;
use App\Models\InventoryUserBranch;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class InventoryBranchScope
{
    public static function canOperateAll(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->can(RolePermissionSeeder::PERMISSION_INVENTORY_OPERATE_ALL_BRANCHES);
    }

    /**
     * @return list<int>|null Null means every branch.
     */
    public static function allowedBranchIds(?User $user): ?array
    {
        if ($user === null) {
            return [];
        }

        if (self::canOperateAll($user)) {
            return null;
        }

        return InventoryUserBranch::query()
            ->where('user_id', $user->id)
            ->pluck('branch_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @return Collection<int, InventoryBranch>
     */
    public static function allowedBranches(?User $user, bool $activeOnly = true): Collection
    {
        $query = InventoryBranch::query()->orderBy('name');
        if ($activeOnly) {
            $query->where('is_active', true);
        }

        $ids = self::allowedBranchIds($user);
        if ($ids === null) {
            return $query->get();
        }

        if ($ids === []) {
            return $query->whereRaw('0 = 1')->get();
        }

        return $query->whereIn('id', $ids)->get();
    }

    public static function allows(?User $user, InventoryBranch $branch): bool
    {
        $ids = self::allowedBranchIds($user);
        if ($ids === null) {
            return true;
        }

        return in_array($branch->id, $ids, true);
    }

    public static function assertCanOperate(?User $user, InventoryBranch $branch): void
    {
        if (self::allows($user, $branch)) {
            return;
        }

        throw new HttpException(403, 'You cannot operate inventory at this branch.');
    }

    public static function assertCanTransfer(?User $user, InventoryBranch $from, InventoryBranch $to): void
    {
        if (self::allows($user, $from) && self::allows($user, $to)) {
            return;
        }

        throw new HttpException(403, 'You cannot transfer stock between these branches.');
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public static function constrain(Builder $query, ?User $user, string $column = 'branch_id'): Builder
    {
        $ids = self::allowedBranchIds($user);
        if ($ids === null) {
            return $query;
        }

        if ($ids === []) {
            return $query->whereRaw('0 = 1');
        }

        return $query->whereIn($column, $ids);
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public static function constrainTransfer(Builder $query, ?User $user): Builder
    {
        $ids = self::allowedBranchIds($user);
        if ($ids === null) {
            return $query;
        }

        if ($ids === []) {
            return $query->whereRaw('0 = 1');
        }

        return $query->where(function (Builder $inner) use ($ids): void {
            $inner->whereIn('from_branch_id', $ids)
                ->orWhereIn('to_branch_id', $ids);
        });
    }

    public static function needsAssignment(?User $user): bool
    {
        return $user !== null
            && ! self::canOperateAll($user)
            && self::allowedBranchIds($user) === [];
    }

    public static function requireBranchId(mixed $branchId, ?User $user, string $field = 'branch_id'): InventoryBranch
    {
        $id = (int) $branchId;
        if ($id < 1) {
            throw ValidationException::withMessages([
                $field => 'Select a branch.',
            ]);
        }

        $branch = InventoryBranch::query()->find($id);
        if ($branch === null) {
            throw ValidationException::withMessages([
                $field => 'Branch was not found.',
            ]);
        }

        if (! self::allows($user, $branch)) {
            throw ValidationException::withMessages([
                $field => 'You cannot operate at this branch.',
            ]);
        }

        return $branch;
    }
}
