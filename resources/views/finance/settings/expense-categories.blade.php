@extends('layouts.app')

@section('title', 'Expense Categories')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Finance · Settings</p>
        <h1 class="h3 mb-1">Expense Categories</h1>
        <p class="text-muted mb-0">Categories with default expense GL accounts for posting.</p>
    </div>

    @include('finance.partials.workspace-nav', ['active' => 'settings'])
    @include('finance.partials.settings-nav', ['active' => 'expense_categories'])

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3">
                    <h2 class="h6 mb-0">Add Expense Category</h2>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('finance.settings.expense-categories.store') }}">
                        @csrf
                        <div class="mb-3">
                            <label for="expense_category_name" class="form-label">Name</label>
                            <input type="text" id="expense_category_name" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name') }}" required maxlength="255">
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-3">
                            <label for="default_gl_account_id" class="form-label">Default GL</label>
                            <select id="default_gl_account_id" name="default_gl_account_id" class="form-select @error('default_gl_account_id') is-invalid @enderror">
                                <option value="">Default misc expense GL</option>
                                @foreach($glAccounts as $gl)
                                    <option value="{{ $gl->id }}" @selected((string) old('default_gl_account_id') === (string) $gl->id)>
                                        {{ $gl->code }} — {{ $gl->name }}
                                    </option>
                                @endforeach
                            </select>
                            @error('default_gl_account_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Add Category</button>
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
                                <th>Name / Default GL</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($expenseCategories as $expenseCategory)
                                <tr>
                                    <td>
                                        @if($expenseCategory->is_active)
                                            <span class="badge text-bg-success">Active</span>
                                        @else
                                            <span class="badge text-bg-secondary">Inactive</span>
                                        @endif
                                    </td>
                                    <td>
                                        <form method="POST" action="{{ route('finance.settings.expense-categories.update', $expenseCategory) }}" class="row g-2 align-items-center">
                                            @csrf
                                            @method('PUT')
                                            <div class="col-md-5">
                                                <input type="text" name="name" class="form-control form-control-sm" value="{{ old('name', $expenseCategory->name) }}" required maxlength="255">
                                            </div>
                                            <div class="col-md-5">
                                                <select name="default_gl_account_id" class="form-select form-select-sm">
                                                    <option value="">—</option>
                                                    @foreach($glAccounts as $gl)
                                                        <option value="{{ $gl->id }}" @selected((int) old('default_gl_account_id', $expenseCategory->default_gl_account_id) === $gl->id)>
                                                            {{ $gl->code }} — {{ $gl->name }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="col-md-2">
                                                <button type="submit" class="btn btn-sm btn-outline-primary w-100">Save</button>
                                            </div>
                                        </form>
                                    </td>
                                    <td class="text-end">
                                        <form method="POST" action="{{ route('finance.settings.expense-categories.toggle', $expenseCategory) }}" class="d-inline">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="btn btn-sm btn-outline-secondary">
                                                {{ $expenseCategory->is_active ? 'Deactivate' : 'Activate' }}
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="text-muted text-center py-4">No expense categories configured.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection
