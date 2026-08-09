<?php

namespace App\Support\Todos;

use App\Enums\ReminderStatus;
use App\Enums\TodoStatus;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TodoPanelRenderer
{
    /**
     * @return \Illuminate\Support\Collection<int, User>
     */
    public function assignableUsers(?User $actor)
    {
        if ($actor === null || ! $actor->can('todos.assign')) {
            return collect();
        }

        return User::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }

    /**
     * @param  array{
     *     todos: LengthAwarePaginator,
     *     filters: array{status: string, scope: string}
     * }  $data
     */
    public function list(array $data, ?string $flashStatus = null, int $status = 200): Response
    {
        return $this->respond('todos.partials.panel-list', $data, $flashStatus, $status);
    }

    public function createForm(Request $request, ?string $flashStatus = null, int $status = 200): Response
    {
        return $this->respond('todos.partials.panel-form', [
            'assignableUsers' => $this->assignableUsers($request->user()),
        ], $flashStatus, $status);
    }

    public function createFormWithErrors(Request $request, Validator $validator): Response
    {
        return $this->respondWithErrors('todos.partials.panel-form', [
            'assignableUsers' => $this->assignableUsers($request->user()),
        ], $validator);
    }

    public function editForm(Request $request, Todo $todo, ?string $flashStatus = null, int $status = 200): Response
    {
        $todo->load(['reminders']);

        return $this->respond('todos.partials.panel-form', [
            'todo' => $todo,
            'assignableUsers' => $this->assignableUsers($request->user()),
            'pendingReminder' => $todo->reminders
                ->first(fn ($reminder) => $reminder->status === ReminderStatus::Pending),
        ], $flashStatus, $status);
    }

    public function editFormWithErrors(Request $request, Todo $todo, Validator $validator): Response
    {
        $todo->load(['reminders']);

        return $this->respondWithErrors('todos.partials.panel-form', [
            'todo' => $todo,
            'assignableUsers' => $this->assignableUsers($request->user()),
            'pendingReminder' => $todo->reminders
                ->first(fn ($reminder) => $reminder->status === ReminderStatus::Pending),
        ], $validator);
    }

    public function detail(Request $request, Todo $todo, ?string $flashStatus = null, int $status = 200): Response
    {
        $todo->load(['creator', 'assignee', 'reminders']);

        return $this->respond('todos.partials.panel-detail', [
            'todo' => $todo,
            'assignableUsers' => $this->assignableUsers($request->user()),
            'pendingReminder' => $todo->reminders
                ->first(fn ($reminder) => $reminder->status === ReminderStatus::Pending),
        ], $flashStatus, $status);
    }

    public function detailWithErrors(Request $request, Todo $todo, Validator $validator): Response
    {
        $todo->load(['creator', 'assignee', 'reminders']);

        return $this->respondWithErrors('todos.partials.panel-detail', [
            'todo' => $todo,
            'assignableUsers' => $this->assignableUsers($request->user()),
            'pendingReminder' => $todo->reminders
                ->first(fn ($reminder) => $reminder->status === ReminderStatus::Pending),
        ], $validator);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function respondWithErrors(string $view, array $data, Validator $validator): Response
    {
        return response(
            view($view, $data)->withErrors($validator),
            422,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function respond(string $view, array $data, ?string $flashStatus, int $status): Response
    {
        $response = response()->view($view, $data, $status);

        if ($flashStatus !== null) {
            $response->header('X-Todo-Status', $flashStatus);
        }

        return $response;
    }

    public static function wantsPanel(Request $request): bool
    {
        return $request->ajax() || $request->boolean('panel');
    }

    /**
     * @return array{status: string, scope: string}
     */
    public static function filtersFromRequest(Request $request): array
    {
        return [
            'status' => (string) $request->string('status')->trim(),
            'scope' => (string) $request->string('scope')->trim(),
        ];
    }

    public static function applyIndexScopes($query, Request $request, User $user)
    {
        $filters = self::filtersFromRequest($request);
        $statusFilter = $filters['status'];
        $scopeFilter = $filters['scope'];

        return $query
            ->when(! $user->can('todos.manage'), function ($scoped) use ($user): void {
                $scoped->where(function ($inner) use ($user): void {
                    $inner->where('created_by', $user->id)
                        ->orWhere('assigned_to', $user->id);
                });
            })
            ->when($scopeFilter === 'assigned', function ($scoped) use ($user): void {
                $scoped->where('assigned_to', $user->id);
            })
            ->when($scopeFilter === 'created', function ($scoped) use ($user): void {
                $scoped->where('created_by', $user->id);
            })
            ->when(
                $statusFilter !== ''
                    && $statusFilter !== 'all'
                    && in_array($statusFilter, TodoStatus::values(), true),
                function ($scoped) use ($statusFilter): void {
                    $scoped->where('status', $statusFilter);
                },
            )
            ->when($statusFilter === '', function ($scoped): void {
                $scoped->where('status', TodoStatus::Open->value);
            });
    }
}
