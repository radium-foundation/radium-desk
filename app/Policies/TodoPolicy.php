<?php

namespace App\Policies;

use App\Models\Todo;
use App\Models\User;

class TodoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('todos.view');
    }

    public function view(User $user, Todo $todo): bool
    {
        if (! $user->can('todos.view')) {
            return false;
        }

        if ($user->can('todos.manage')) {
            return true;
        }

        return $this->isCreatorOrAssignee($user, $todo);
    }

    public function create(User $user): bool
    {
        return $user->can('todos.create');
    }

    public function update(User $user, Todo $todo): bool
    {
        if (! $user->can('todos.update')) {
            return false;
        }

        if ($user->can('todos.manage')) {
            return true;
        }

        return $this->isCreatorOrAssignee($user, $todo);
    }

    public function complete(User $user, Todo $todo): bool
    {
        if (! $user->can('todos.update')) {
            return false;
        }

        if ($user->can('todos.manage')) {
            return true;
        }

        return $this->isCreatorOrAssignee($user, $todo);
    }

    public function assign(User $user, Todo $todo): bool
    {
        return $user->can('todos.assign');
    }

    public function delete(User $user, Todo $todo): bool
    {
        if ($user->can('todos.manage')) {
            return true;
        }

        return $this->isCreator($user, $todo);
    }

    public function cancel(User $user, Todo $todo): bool
    {
        return $this->delete($user, $todo);
    }

    private function isCreator(User $user, Todo $todo): bool
    {
        return (int) $todo->created_by === (int) $user->id;
    }

    private function isCreatorOrAssignee(User $user, Todo $todo): bool
    {
        return $this->isCreator($user, $todo)
            || (int) $todo->assigned_to === (int) $user->id;
    }
}
