@extends('layouts.app')

@section('title', $expense->expense_no)

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Finance</p>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-2">
                <li class="breadcrumb-item"><a href="{{ route('finance.expenses.index') }}">Expenses</a></li>
                <li class="breadcrumb-item active" aria-current="page">{{ $expense->expense_no }}</li>
            </ol>
        </nav>
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
            <div>
                <h1 class="h3 mb-1">{{ $expense->expense_no }}</h1>
                <p class="text-muted mb-0">{{ display_app_date($expense->expense_date) }} · {{ number_format((float) $expense->amount, 2) }}</p>
            </div>
            @include('finance.expenses.partials.status-badge', ['status' => $expense->status])
        </div>
    </div>

    @include('finance.partials.workspace-nav', ['active' => 'expenses'])

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-3">Category</dt>
                        <dd class="col-sm-9">{{ $expense->category?->name ?? '—' }}</dd>

                        <dt class="col-sm-3">Payment method</dt>
                        <dd class="col-sm-9">{{ $expense->paymentMethod?->name ?? '—' }}</dd>

                        <dt class="col-sm-3">Paid from</dt>
                        <dd class="col-sm-9">
                            @if($expense->cashAccount)
                                Cash · {{ $expense->cashAccount->name }}
                            @elseif($expense->bankAccount)
                                Bank · {{ $expense->bankAccount->bank_name }} · {{ $expense->bankAccount->account_name }} ({{ $expense->bankAccount->last_four }})
                            @else
                                —
                            @endif
                        </dd>

                        <dt class="col-sm-3">Description</dt>
                        <dd class="col-sm-9">{{ $expense->description }}</dd>

                        <dt class="col-sm-3">Receipt</dt>
                        <dd class="col-sm-9">
                            @if($expense->hasReceipt())
                                <a href="{{ route('finance.expenses.receipt', $expense) }}" target="_blank" rel="noopener">
                                    View receipt
                                </a>
                            @else
                                —
                            @endif
                        </dd>

                        <dt class="col-sm-3">Created by</dt>
                        <dd class="col-sm-9">{{ $expense->creator?->name ?? '—' }} · {{ display_app_datetime_24($expense->created_at) }}</dd>

                        @if($expense->isPosted())
                            <dt class="col-sm-3">Posted by</dt>
                            <dd class="col-sm-9">{{ $expense->poster?->name ?? '—' }} · {{ display_app_datetime_24($expense->posted_at) }}</dd>
                            @if($expense->journal)
                                <dt class="col-sm-3">Journal</dt>
                                <dd class="col-sm-9">
                                    <a href="{{ route('finance.settings.journals.show', $expense->journal) }}">
                                        {{ $expense->journal->journal_no }}
                                    </a>
                                </dd>
                            @endif
                        @endif
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3">
                    <h2 class="h6 mb-0">Actions</h2>
                </div>
                <div class="card-body d-grid gap-2">
                    @if($expense->isDraft())
                        <a href="{{ route('finance.expenses.edit', $expense) }}" class="btn btn-outline-primary">Edit Draft</a>
                        <form
                            method="POST"
                            action="{{ route('finance.expenses.post', $expense) }}"
                            onsubmit="return confirm('Post this expense? It cannot be edited afterward.');"
                        >
                            @csrf
                            <button type="submit" class="btn btn-success w-100">Post Expense</button>
                        </form>
                        <p class="text-muted small mb-0">Posting locks the expense. Future phases may add reversals only.</p>
                    @else
                        <p class="text-muted small mb-0">This expense is posted and cannot be edited or deleted.</p>
                    @endif
                    <a href="{{ route('finance.expenses.index') }}" class="btn btn-outline-secondary">Back to list</a>
                </div>
            </div>
        </div>
    </div>
@endsection
