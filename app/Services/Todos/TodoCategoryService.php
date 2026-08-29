<?php

namespace App\Services\Todos;

use App\Models\TodoCategory;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TodoCategoryService
{
    public const EVENT_CREATED = 'todo_category.created';

    public const EVENT_UPDATED = 'todo_category.updated';

    public const EVENT_ACTIVATED = 'todo_category.activated';

    public const EVENT_DEACTIVATED = 'todo_category.deactivated';

    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    public function create(User $actor, string $name): TodoCategory
    {
        Gate::forUser($actor)->authorize('create', TodoCategory::class);

        $name = $this->normalizedName($name);

        return DB::transaction(function () use ($actor, $name): TodoCategory {
            $this->assertNameAvailable($name);

            $category = TodoCategory::query()->create([
                'name' => $name,
                'is_active' => true,
            ]);

            $this->auditLogService->log(
                userId: $actor->id,
                event: self::EVENT_CREATED,
                auditable: $category,
                newValues: [
                    'name' => $category->name,
                    'is_active' => true,
                ],
            );

            return $category;
        });
    }

    public function update(User $actor, TodoCategory $category, string $name): TodoCategory
    {
        Gate::forUser($actor)->authorize('update', $category);

        $name = $this->normalizedName($name);

        return DB::transaction(function () use ($actor, $category, $name): TodoCategory {
            $locked = $this->lockCategory($category);
            $this->assertNameAvailable($name, (int) $locked->id);

            $oldValues = [
                'name' => $locked->name,
                'is_active' => (bool) $locked->is_active,
            ];

            $locked->update(['name' => $name]);
            $locked = $locked->fresh() ?? $locked;

            $this->auditLogService->log(
                userId: $actor->id,
                event: self::EVENT_UPDATED,
                auditable: $locked,
                oldValues: $oldValues,
                newValues: [
                    'name' => $locked->name,
                    'is_active' => (bool) $locked->is_active,
                ],
            );

            return $locked;
        });
    }

    public function toggle(User $actor, TodoCategory $category): TodoCategory
    {
        Gate::forUser($actor)->authorize('toggle', $category);

        return DB::transaction(function () use ($actor, $category): TodoCategory {
            $locked = $this->lockCategory($category);
            $wasActive = (bool) $locked->is_active;
            $locked->update(['is_active' => ! $wasActive]);
            $locked = $locked->fresh() ?? $locked;

            $this->auditLogService->log(
                userId: $actor->id,
                event: $locked->is_active ? self::EVENT_ACTIVATED : self::EVENT_DEACTIVATED,
                auditable: $locked,
                oldValues: ['is_active' => $wasActive],
                newValues: ['is_active' => (bool) $locked->is_active],
            );

            return $locked;
        });
    }

    private function lockCategory(TodoCategory $category): TodoCategory
    {
        $locked = TodoCategory::query()->whereKey($category->id)->lockForUpdate()->first();

        if ($locked === null) {
            throw ValidationException::withMessages([
                'todo_category' => 'The to-do category could not be found.',
            ]);
        }

        return $locked;
    }

    private function assertNameAvailable(string $name, ?int $ignoreId = null): void
    {
        $exists = TodoCategory::query()
            ->where('name', $name)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => 'A to-do category with this name already exists. Activate the existing category instead of creating a duplicate.',
            ]);
        }
    }

    private function normalizedName(string $name): string
    {
        $trimmed = trim($name);

        if ($trimmed === '') {
            throw ValidationException::withMessages([
                'name' => 'A category name is required.',
            ]);
        }

        return $trimmed;
    }
}
