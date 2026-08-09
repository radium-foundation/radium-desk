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
use App\Support\Todos\TodoPanelRenderer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class TodoController extends Controller
{
    public function __construct(
        private readonly TodoService $todoService,
        private readonly TodoPanelRenderer $panelRenderer,
    ) {
        $this->authorizeResource(Todo::class, 'todo');
    }

    public function index(Request $request): View|Response
    {
        $data = $this->indexData($request);

        if ($this->wantsTodoPanel($request)) {
            return $this->panelRenderer->list($data);
        }

        return view('todos.index', $data);
    }

    public function create(Request $request): View|Response
    {
        if ($this->wantsTodoPanel($request)) {
            return $this->panelRenderer->createForm($request);
        }

        return view('todos.create', [
            'assignableUsers' => $this->panelRenderer->assignableUsers($request->user()),
        ]);
    }

    public function store(StoreTodoRequest $request): RedirectResponse|Response
    {
        $todo = $this->todoService->create(
            actor: $request->user(),
            data: $request->todoData(),
        );

        if ($this->wantsTodoPanel($request)) {
            return $this->panelRenderer->detail($request, $todo, 'todo-created');
        }

        return redirect()
            ->route('todos.show', $todo)
            ->with('status', 'todo-created');
    }

    public function show(Request $request, Todo $todo): View|Response
    {
        if ($this->wantsTodoPanel($request)) {
            return $this->panelRenderer->detail($request, $todo);
        }

        $todo->load(['creator', 'assignee', 'reminders']);

        return view('todos.show', [
            'todo' => $todo,
            'assignableUsers' => $this->panelRenderer->assignableUsers($request->user()),
            'pendingReminder' => $todo->reminders
                ->first(fn ($reminder) => $reminder->status === ReminderStatus::Pending),
        ]);
    }

    public function edit(Request $request, Todo $todo): View|Response
    {
        if ($this->wantsTodoPanel($request)) {
            return $this->panelRenderer->editForm($request, $todo);
        }

        $todo->load(['reminders']);

        return view('todos.edit', [
            'todo' => $todo,
            'assignableUsers' => $this->panelRenderer->assignableUsers($request->user()),
            'pendingReminder' => $todo->reminders
                ->first(fn ($reminder) => $reminder->status === ReminderStatus::Pending),
        ]);
    }

    public function update(UpdateTodoRequest $request, Todo $todo): RedirectResponse|Response
    {
        $todo = $this->todoService->update(
            actor: $request->user(),
            todo: $todo,
            data: $request->todoData(),
        );

        if ($this->wantsTodoPanel($request)) {
            return $this->panelRenderer->detail($request, $todo, 'todo-updated');
        }

        return redirect()
            ->route('todos.show', $todo)
            ->with('status', 'todo-updated');
    }

    public function destroy(Request $request, Todo $todo): RedirectResponse|Response
    {
        $this->todoService->delete(
            actor: $request->user(),
            todo: $todo,
        );

        if ($this->wantsTodoPanel($request)) {
            return $this->panelRenderer->list($this->indexData($request), 'todo-deleted');
        }

        return redirect()
            ->route('todos.index')
            ->with('status', 'todo-deleted');
    }

    public function complete(Request $request, Todo $todo): RedirectResponse|Response
    {
        $this->authorize('complete', $todo);

        $todo = $this->todoService->complete(
            actor: $request->user(),
            todo: $todo,
        );

        if ($this->wantsTodoPanel($request)) {
            return $this->panelRenderer->detail($request, $todo, 'todo-completed');
        }

        return redirect()
            ->route('todos.show', $todo)
            ->with('status', 'todo-completed');
    }

    public function reopen(Request $request, Todo $todo): RedirectResponse|Response
    {
        $this->authorize('update', $todo);

        $todo = $this->todoService->reopen(
            actor: $request->user(),
            todo: $todo,
        );

        if ($this->wantsTodoPanel($request)) {
            return $this->panelRenderer->detail($request, $todo, 'todo-reopened');
        }

        return redirect()
            ->route('todos.show', $todo)
            ->with('status', 'todo-reopened');
    }

    public function cancel(Request $request, Todo $todo): RedirectResponse|Response
    {
        $this->authorize('cancel', $todo);

        $todo = $this->todoService->cancel(
            actor: $request->user(),
            todo: $todo,
        );

        if ($this->wantsTodoPanel($request)) {
            return $this->panelRenderer->detail($request, $todo, 'todo-cancelled');
        }

        return redirect()
            ->route('todos.show', $todo)
            ->with('status', 'todo-cancelled');
    }

    public function assign(AssignTodoRequest $request, Todo $todo): RedirectResponse|Response
    {
        $assignee = User::query()->findOrFail((int) $request->validated('assigned_to'));

        $todo = $this->todoService->assign(
            actor: $request->user(),
            todo: $todo,
            assignee: $assignee,
        );

        if ($this->wantsTodoPanel($request)) {
            return $this->panelRenderer->detail($request, $todo, 'todo-assigned');
        }

        return redirect()
            ->route('todos.show', $todo)
            ->with('status', 'todo-assigned');
    }

    /**
     * @return array{
     *     todos: \Illuminate\Contracts\Pagination\LengthAwarePaginator,
     *     filters: array{status: string, scope: string}
     * }
     */
    private function indexData(Request $request): array
    {
        $user = $request->user();
        $filters = TodoPanelRenderer::filtersFromRequest($request);

        $todos = TodoPanelRenderer::applyIndexScopes(
            Todo::query()->with(['creator', 'assignee', 'reminders']),
            $request,
            $user,
        )
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [TodoStatus::Open->value])
            ->orderBy('due_at')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return [
            'todos' => $todos,
            'filters' => $filters,
        ];
    }

    private function wantsTodoPanel(Request $request): bool
    {
        return TodoPanelRenderer::wantsPanel($request);
    }
}
