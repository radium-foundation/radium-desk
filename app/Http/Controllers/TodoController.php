<?php

namespace App\Http\Controllers;

use App\Enums\ReminderStatus;
use App\Enums\TodoStatus;
use App\Http\Requests\AssignTodoRequest;
use App\Http\Requests\StoreTodoRequest;
use App\Http\Requests\UpdateTodoRequest;
use App\Models\Todo;
use App\Models\User;
use App\Services\Todos\TodoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TodoController extends Controller
{
    public function __construct(
        private readonly TodoService $todoService,
    ) {
        $this->authorizeResource(Todo::class, 'todo');
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        $statusFilter = (string) $request->string('status')->trim();
        $scopeFilter = (string) $request->string('scope')->trim();

        $todos = Todo::query()
            ->with(['creator', 'assignee', 'reminders'])
            ->when(! $user->can('todos.manage'), function ($query) use ($user): void {
                $query->where(function ($scoped) use ($user): void {
                    $scoped->where('created_by', $user->id)
                        ->orWhere('assigned_to', $user->id);
                });
            })
            ->when($scopeFilter === 'assigned', function ($query) use ($user): void {
                $query->where('assigned_to', $user->id);
            })
            ->when($scopeFilter === 'created', function ($query) use ($user): void {
                $query->where('created_by', $user->id);
            })
            ->when(
                $statusFilter !== ''
                    && $statusFilter !== 'all'
                    && in_array($statusFilter, TodoStatus::values(), true),
                function ($query) use ($statusFilter): void {
                    $query->where('status', $statusFilter);
                },
            )
            ->when($statusFilter === '', function ($query): void {
                $query->where('status', TodoStatus::Open->value);
            })
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [TodoStatus::Open->value])
            ->orderBy('due_at')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('todos.index', [
            'todos' => $todos,
            'filters' => [
                'status' => $statusFilter,
                'scope' => $scopeFilter,
            ],
        ]);
    }

    public function create(): View
    {
        return view('todos.create', [
            'assignableUsers' => $this->assignableUsers(request()->user()),
        ]);
    }

    public function store(StoreTodoRequest $request): RedirectResponse
    {
        $todo = $this->todoService->create(
            actor: $request->user(),
            data: $request->todoData(),
        );

        return redirect()
            ->route('todos.show', $todo)
            ->with('status', 'todo-created');
    }

    public function show(Todo $todo): View
    {
        $todo->load(['creator', 'assignee', 'reminders']);

        return view('todos.show', [
            'todo' => $todo,
            'assignableUsers' => $this->assignableUsers(request()->user()),
            'pendingReminder' => $todo->reminders
                ->first(fn ($reminder) => $reminder->status === ReminderStatus::Pending),
        ]);
    }

    public function edit(Todo $todo): View
    {
        $todo->load(['reminders']);

        return view('todos.edit', [
            'todo' => $todo,
            'assignableUsers' => $this->assignableUsers(request()->user()),
            'pendingReminder' => $todo->reminders
                ->first(fn ($reminder) => $reminder->status === ReminderStatus::Pending),
        ]);
    }

    public function update(UpdateTodoRequest $request, Todo $todo): RedirectResponse
    {
        $todo = $this->todoService->update(
            actor: $request->user(),
            todo: $todo,
            data: $request->todoData(),
        );

        return redirect()
            ->route('todos.show', $todo)
            ->with('status', 'todo-updated');
    }

    public function destroy(Request $request, Todo $todo): RedirectResponse
    {
        $this->todoService->delete(
            actor: $request->user(),
            todo: $todo,
        );

        return redirect()
            ->route('todos.index')
            ->with('status', 'todo-deleted');
    }

    public function complete(Request $request, Todo $todo): RedirectResponse
    {
        $this->authorize('complete', $todo);

        $todo = $this->todoService->complete(
            actor: $request->user(),
            todo: $todo,
        );

        return redirect()
            ->route('todos.show', $todo)
            ->with('status', 'todo-completed');
    }

    public function reopen(Request $request, Todo $todo): RedirectResponse
    {
        $this->authorize('update', $todo);

        $todo = $this->todoService->reopen(
            actor: $request->user(),
            todo: $todo,
        );

        return redirect()
            ->route('todos.show', $todo)
            ->with('status', 'todo-reopened');
    }

    public function cancel(Request $request, Todo $todo): RedirectResponse
    {
        $this->authorize('cancel', $todo);

        $todo = $this->todoService->cancel(
            actor: $request->user(),
            todo: $todo,
        );

        return redirect()
            ->route('todos.show', $todo)
            ->with('status', 'todo-cancelled');
    }

    public function assign(AssignTodoRequest $request, Todo $todo): RedirectResponse
    {
        $assignee = User::query()->findOrFail((int) $request->validated('assigned_to'));

        $todo = $this->todoService->assign(
            actor: $request->user(),
            todo: $todo,
            assignee: $assignee,
        );

        return redirect()
            ->route('todos.show', $todo)
            ->with('status', 'todo-assigned');
    }

    /**
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function assignableUsers(?User $actor)
    {
        if ($actor === null || ! $actor->can('todos.assign')) {
            return collect();
        }

        return User::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }
}
