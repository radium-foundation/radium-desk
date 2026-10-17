<?php

namespace App\Support\ReturnExchange;

use App\Models\InventoryBranch;
use App\Models\ReturnExchangeCase;
use App\Models\User;
use App\Support\Inventory\InventoryBranchScope;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ReturnExchangeCaseBranchAccess
{
    public static function hasCentralAccess(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->can(RolePermissionSeeder::PERMISSION_RETURNS_CASES_CENTRAL);
    }

    public static function canView(?User $user, ReturnExchangeCase $case): bool
    {
        if ($user === null) {
            return false;
        }

        if (self::hasCentralAccess($user)) {
            return true;
        }

        if ($case->branch_id === null) {
            return (int) $case->requested_by === (int) $user->id;
        }

        $branchIds = InventoryBranchScope::allowedBranchIds($user);
        if ($branchIds === null || $branchIds === []) {
            return false;
        }

        return in_array((int) $case->branch_id, $branchIds, true);
    }

    public static function assertCanView(?User $user, ReturnExchangeCase $case): void
    {
        if (self::canView($user, $case)) {
            return;
        }

        throw new HttpException(403, 'You cannot view this return case.');
    }

    public static function assertCanCreateForBranch(?User $user, ?int $branchId): void
    {
        if ($branchId === null) {
            return;
        }

        if (self::hasCentralAccess($user)) {
            return;
        }

        $branchIds = InventoryBranchScope::allowedBranchIds($user);
        if ($branchIds === null || $branchIds === []) {
            throw new HttpException(403, 'You are not assigned to a branch for this return case.');
        }

        $branch = InventoryBranch::query()->find($branchId);
        if ($branch === null) {
            throw ValidationException::withMessages([
                'branch_id' => 'Branch was not found.',
            ]);
        }

        InventoryBranchScope::assertCanOperate($user, $branch);
    }

    /**
     * Branch receipt/inspection: central or assigned branch operator only (never unassigned non-central).
     */
    public static function assertCanOperateOnCase(?User $user, ReturnExchangeCase $case): void
    {
        if ($case->branch_id === null) {
            if (! self::hasCentralAccess($user)) {
                throw new HttpException(403, 'Only central staff can receive goods on branchless cases.');
            }

            return;
        }

        if (self::hasCentralAccess($user)) {
            return;
        }

        $branchIds = InventoryBranchScope::allowedBranchIds($user);
        if ($branchIds === null || $branchIds === []) {
            throw new HttpException(403, 'You are not assigned to receive goods for this branch.');
        }

        $branch = InventoryBranch::query()->find($case->branch_id);
        if ($branch === null) {
            throw ValidationException::withMessages([
                'branch_id' => 'Branch was not found.',
            ]);
        }

        InventoryBranchScope::assertCanOperate($user, $branch);
    }

    /**
     * @param  Builder<ReturnExchangeCase>  $query
     * @return Builder<ReturnExchangeCase>
     */
    public static function constrainVisible(Builder $query, ?User $user): Builder
    {
        if ($user === null) {
            return $query->whereRaw('0 = 1');
        }

        if (self::hasCentralAccess($user)) {
            return $query;
        }

        $branchIds = InventoryBranchScope::allowedBranchIds($user);

        if ($branchIds === null || $branchIds === []) {
            return $query->where('requested_by', $user->id);
        }

        return $query->where(function (Builder $inner) use ($user, $branchIds): void {
            $inner->whereIn('branch_id', $branchIds)
                ->orWhere(function (Builder $branchless) use ($user): void {
                    $branchless->whereNull('branch_id')
                        ->where('requested_by', $user->id);
                });
        });
    }
}
