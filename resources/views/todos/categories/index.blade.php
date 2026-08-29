@extends('layouts.app')

@section('title', 'To-Do Categories')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Administration</p>
        <h1 class="h3 mb-1">To-Do Categories</h1>
        <p class="text-muted mb-0">Admin-managed labels for grouping to-dos. Deactivate instead of deleting so existing to-dos keep their history. Categories can be retired later after similar labels are merged.</p>
    </div>

    @include('navigation.administration-workspace-nav', ['active' => 'todo_categories'])

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3">
                    <h2 class="h6 mb-0">Add category</h2>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('todo-categories.store') }}">
                        @csrf
                        <div class="mb-3">
                            <label for="todo_category_name" class="form-label">Name</label>
                            <input
                                type="text"
                                id="todo_category_name"
                                name="name"
                                class="form-control @error('name') is-invalid @enderror"
                                value="{{ old('name') }}"
                                required
                                maxlength="255"
                            >
                            @error('name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Add category</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Status</th>
                                <th>Name</th>
                                <th class="text-end">To-dos</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($categories as $category)
                                <tr>
                                    <td>
                                        @if($category->is_active)
                                            <span class="badge text-bg-success">Active</span>
                                        @else
                                            <span class="badge text-bg-secondary">Inactive</span>
                                        @endif
                                    </td>
                                    <td>
                                        <form method="POST" action="{{ route('todo-categories.update', $category) }}" class="d-flex gap-2 align-items-center">
                                            @csrf
                                            @method('PUT')
                                            <input
                                                type="text"
                                                name="name"
                                                class="form-control form-control-sm @error('name') is-invalid @enderror"
                                                value="{{ old('name', $category->name) }}"
                                                required
                                                maxlength="255"
                                                aria-label="Category name"
                                            >
                                            <button type="submit" class="btn btn-sm btn-outline-primary flex-shrink-0">Save</button>
                                        </form>
                                    </td>
                                    <td class="text-end text-muted">{{ number_format($category->todos_count) }}</td>
                                    <td class="text-end">
                                        <form method="POST" action="{{ route('todo-categories.toggle', $category) }}" class="d-inline">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="btn btn-sm btn-outline-secondary">
                                                {{ $category->is_active ? 'Deactivate' : 'Activate' }}
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-muted text-center py-4">No to-do categories yet. Add one to start grouping to-dos.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection
