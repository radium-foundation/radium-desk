<?php

namespace App\Policies;

use App\Models\TodoCategory;
use App\Models\User;

class TodoCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('todos.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('todos.manage');
    }

    public function update(User $user, TodoCategory $todoCategory): bool
    {
        return $user->can('todos.manage');
    }

    public function toggle(User $user, TodoCategory $todoCategory): bool
    {
        return $user->can('todos.manage');
    }
}
