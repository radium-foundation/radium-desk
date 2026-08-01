@extends('layouts.app')

@section('title', 'Expenses')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Finance</p>
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
            <div>
                <h1 class="h3 mb-1">Expenses</h1>
                <p class="text-muted mb-0">Operational expense tracker. Drafts can be edited; posted expenses are immutable.</p>
            </div>
            <a href="{{ route('finance.expenses.create') }}" class="btn btn-primary">
                <i class="bi bi-plus-lg me-1"></i> New Expense
            </a>
        </div>
    </div>

    @include('finance.partials.workspace-nav', ['active' => 'expenses'])

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('finance.expenses.index') }}" class="row g-3">
                <div class="col-md-3">
                    <label for="q" class="form-label">Search</label>
                    <input
                        type="text"
                        id="q"
                        name="q"
                        class="form-control"
                        value="{{ $filters['q'] ?? '' }}"
                        placeholder="Expense no or description"
                    >
                </div>
                <div class="col-md-2">
                    <label for="status" class="form-label">Status</label>
                    <select id="status" name="status" class="form-select">
                        <option value="">All</option>
                        @foreach(\App\Enums\FinanceExpenseStatus::cases() as $statusOption)
                            <option value="{{ $statusOption->value }}" @selected(($filters['status'] ?? '') === $statusOption->value)>
                                {{ $statusOption->label() }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="expense_category_id" class="form-label">Category</label>
                    <select id="expense_category_id" name="expense_category_id" class="form-select">
                        <option value="">All</option>
                        @foreach($categories as $category)
                            <option value="{{ $category->id }}" @selected((string) ($filters['expense_category_id'] ?? '') === (string) $category->id)>
                                {{ $category->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="date_from" class="form-label">From</label>
                    <input type="date" id="date_from" name="date_from" class="form-control" value="{{ $filters['date_from'] ?? '' }}">
                </div>
                <div class="col-md-2">
                    <label for="date_to" class="form-label">To</label>
                    <input type="date" id="date_to" name="date_to" class="form-control" value="{{ $filters['date_to'] ?? '' }}">
                </div>
                <div class="col-md-1 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-primary w-100">Filter</button>
                </div>
            </form>
            <div class="mt-2">
                <a href="{{ route('finance.expenses.index') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Expense No</th>
                        <th>Date</th>
                        <th>Category</th>
                        <th class="text-end">Amount</th>
                        <th>Paid From</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($expenses as $expense)
                        <tr>
                            <td class="fw-semibold">{{ $expense->expense_no }}</td>
                            <td>{{ display_app_date($expense->expense_date) }}</td>
                            <td>{{ $expense->category?->name ?? '—' }}</td>
                            <td class="text-end">{{ number_format((float) $expense->amount, 2) }}</td>
                            <td>
                                @if($expense->cashAccount)
                                    Cash · {{ $expense->cashAccount->name }}
                                @elseif($expense->bankAccount)
                                    Bank · {{ $expense->bankAccount->account_name }}
                                @else
                                    —
                                @endif
                            </td>
                            <td>@include('finance.expenses.partials.status-badge', ['status' => $expense->status])</td>
                            <td class="text-end">
                                <a href="{{ route('finance.expenses.show', $expense) }}" class="btn btn-sm btn-outline-primary">View</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-muted text-center py-4">No expenses found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($expenses->hasPages())
            <div class="card-footer bg-white">
                {{ $expenses->links() }}
            </div>
        @endif
    </div>
@endsection
