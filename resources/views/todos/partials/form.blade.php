@php
    /** @var \App\Models\Todo|null $todo */
    /** @var \Illuminate\Support\Collection<int, \App\Models\User> $assignableUsers */
    /** @var \Illuminate\Support\Collection<int, \App\Models\TodoCategory> $categories */
    /** @var \App\Models\Reminder|null $pendingReminder */
    /** @var bool $compact */
    $isEdit = isset($todo) && $todo !== null;
    $compact = $compact ?? false;
    $categories = $categories ?? collect();
    $action = $isEdit ? route('todos.update', $todo) : route('todos.store');
    $canAssign = auth()->user()?->can('todos.assign') ?? false;
    $canManageCategories = auth()->user()?->can('todos.manage') ?? false;
    $defaultDue = old('due_at', $isEdit && $todo->due_at ? $todo->due_at->timezone(config('app.timezone'))->format('Y-m-d\TH:i') : '');
    $defaultRemind = old('remind_at', $isEdit && $pendingReminder?->remind_at
        ? $pendingReminder->remind_at->timezone(config('app.timezone'))->format('Y-m-d\TH:i')
        : '');
    $defaultAssignee = old('assigned_to', $isEdit ? $todo->assigned_to : auth()->id());
    $defaultCategory = old('todo_category_id', $isEdit ? $todo->todo_category_id : '');
    $cancelUrl = $isEdit ? route('todos.show', $todo) : route('todos.index');
@endphp

<form method="POST" action="{{ $action }}" class="todo-form {{ $compact ? 'todo-form--compact' : '' }}">
    @csrf
    @if($isEdit)
        @method('PUT')
    @endif

    <div class="row g-3">
        <div class="col-12">
            <label for="title" class="form-label">Title</label>
            <input type="text" id="title" name="title"
                   class="form-control @error('title') is-invalid @enderror"
                   value="{{ old('title', $isEdit ? $todo->title : '') }}"
                   maxlength="255"
                   required>
            @error('title')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="col-12">
            <label for="description" class="form-label">Description <span class="text-muted">(optional)</span></label>
            <textarea id="description" name="description" rows="{{ $compact ? 2 : 3 }}"
                      class="form-control @error('description') is-invalid @enderror">{{ old('description', $isEdit ? $todo->description : '') }}</textarea>
            @error('description')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="{{ $compact ? 'col-12' : 'col-md-4' }}">
            <label for="todo_category_id" class="form-label">
                Category <span class="text-muted">(optional)</span>
            </label>
            <select id="todo_category_id" name="todo_category_id" class="form-select @error('todo_category_id') is-invalid @enderror">
                <option value="">No category</option>
                @foreach($categories as $category)
                    <option value="{{ $category->id }}" @selected((string) $defaultCategory === (string) $category->id)>
                        {{ $category->name }}@if(! $category->is_active) (inactive)@endif
                    </option>
                @endforeach
            </select>
            @error('todo_category_id')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
            @if($canManageCategories)
                <div class="form-text">
                    <a href="{{ route('todo-categories.index') }}">Manage categories</a>
                </div>
            @elseif($categories->isEmpty())
                <div class="form-text">Categories are added by an admin.</div>
            @endif
        </div>

        <div class="{{ $compact ? 'col-12' : 'col-md-4' }}">
            <label for="priority" class="form-label">Priority</label>
            <select id="priority" name="priority" class="form-select @error('priority') is-invalid @enderror" required>
                @foreach(\App\Enums\TodoPriority::cases() as $priority)
                    <option value="{{ $priority->value }}"
                        @selected(old('priority', $isEdit ? $todo->priority->value : \App\Enums\TodoPriority::Normal->value) === $priority->value)>
                        {{ $priority->label() }}
                    </option>
                @endforeach
            </select>
            @error('priority')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        @if($canAssign && $assignableUsers->isNotEmpty())
            <div class="{{ $compact ? 'col-12' : 'col-md-4' }}">
                <label for="assigned_to" class="form-label">Assignee</label>
                <select id="assigned_to" name="assigned_to" class="form-select @error('assigned_to') is-invalid @enderror">
                    @foreach($assignableUsers as $user)
                        <option value="{{ $user->id }}" @selected((int) $defaultAssignee === (int) $user->id)>
                            {{ $user->name }}
                        </option>
                    @endforeach
                </select>
                @error('assigned_to')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
        @endif

        <div class="{{ $compact ? 'col-12' : 'col-md-6' }}">
            <label for="due_at" class="form-label">Due <span class="text-muted">(optional)</span></label>
            <input type="datetime-local" id="due_at" name="due_at"
                   class="form-control @error('due_at') is-invalid @enderror"
                   value="{{ $defaultDue }}">
            @error('due_at')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
            @unless($compact)
                <div class="form-text">Times use {{ config('app.timezone') }}.</div>
            @endunless
        </div>

        <div class="{{ $compact ? 'col-12' : 'col-md-6' }}">
            <label for="remind_at" class="form-label">Reminder <span class="text-muted">(optional)</span></label>
            <input type="datetime-local" id="remind_at" name="remind_at"
                   class="form-control @error('remind_at') is-invalid @enderror"
                   value="{{ $defaultRemind }}">
            @error('remind_at')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
    </div>

    <div class="d-flex flex-wrap gap-2 mt-3">
        <button type="submit" class="btn btn-primary btn-sm">
            {{ $isEdit ? 'Save' : 'Create' }}
        </button>
        <a href="{{ $cancelUrl }}" class="btn btn-outline-secondary btn-sm" data-todo-panel-nav>Cancel</a>
    </div>
</form>
