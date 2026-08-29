<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTodoCategoryRequest;
use App\Http\Requests\UpdateTodoCategoryRequest;
use App\Models\TodoCategory;
use App\Services\Todos\TodoCategoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class TodoCategoryController extends Controller
{
    public function __construct(
        private readonly TodoCategoryService $todoCategoryService,
    ) {
        $this->authorizeResource(TodoCategory::class, 'todoCategory');
    }

    /**
     * @return array<string, string>
     */
    protected function resourceAbilityMap(): array
    {
        return array_merge(parent::resourceAbilityMap(), [
            'toggle' => 'toggle',
        ]);
    }

    public function index(): View
    {
        return view('todos.categories.index', [
            'categories' => TodoCategory::query()
                ->withCount('todos')
                ->ordered()
                ->get(),
        ]);
    }

    public function store(StoreTodoCategoryRequest $request): RedirectResponse
    {
        $this->todoCategoryService->create(
            actor: $request->user(),
            name: $request->validated('name'),
        );

        return redirect()
            ->route('todo-categories.index')
            ->with('status', 'todo-category-created');
    }

    public function update(UpdateTodoCategoryRequest $request, TodoCategory $todoCategory): RedirectResponse
    {
        $this->todoCategoryService->update(
            actor: $request->user(),
            category: $todoCategory,
            name: $request->validated('name'),
        );

        return redirect()
            ->route('todo-categories.index')
            ->with('status', 'todo-category-updated');
    }

    public function toggle(TodoCategory $todoCategory): RedirectResponse
    {
        $this->authorize('toggle', $todoCategory);

        $todoCategory = $this->todoCategoryService->toggle(
            actor: request()->user(),
            category: $todoCategory,
        );

        return redirect()
            ->route('todo-categories.index')
            ->with(
                'status',
                $todoCategory->is_active
                    ? 'todo-category-activated'
                    : 'todo-category-deactivated',
            );
    }
}
