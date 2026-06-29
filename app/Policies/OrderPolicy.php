<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('orders.view');
    }

    public function view(User $user, Order $order): bool
    {
        return $user->can('orders.view');
    }

    public function create(User $user): bool
    {
        return $user->can('orders.create');
    }

    public function update(User $user, Order $order): bool
    {
        if ($order->isTransactionLocked()) {
            return $user->hasRole(RolePermissionSeeder::ROLE_SUPERADMIN)
                && $user->can('orders.update');
        }

        return $user->can('orders.update');
    }

    public function delete(User $user, Order $order): bool
    {
        return $user->can('orders.delete');
    }

    public function assignTransaction(User $user, Order $order): bool
    {
        return $user->can('orders.update') && ! $order->isTransactionLocked();
    }

    public function unlockTransaction(User $user, Order $order): bool
    {
        return $user->hasRole(RolePermissionSeeder::ROLE_SUPERADMIN) && $order->isTransactionLocked();
    }

    public function assignSerial(User $user, Order $order): bool
    {
        return $user->can('incidents.update') && ! $order->isSerialLocked();
    }

    public function assignDeviceModel(User $user, Order $order): bool
    {
        return $user->can('incidents.update') && ! $order->hasDeviceModelAssigned();
    }

    public function unlockSerial(User $user, Order $order): bool
    {
        return $user->hasRole(RolePermissionSeeder::ROLE_SUPERADMIN) && $order->isSerialLocked();
    }

    public function correctIdentity(User $user, Order $order): bool
    {
        if (! $user->can('orders.update')) {
            return false;
        }

        return $user->hasAnyRole([
            RolePermissionSeeder::ROLE_ADMIN,
            RolePermissionSeeder::ROLE_SUPERADMIN,
        ]);
    }
}
