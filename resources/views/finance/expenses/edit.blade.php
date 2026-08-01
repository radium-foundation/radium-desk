@extends('layouts.app')

@section('title', 'Edit Expense')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Finance</p>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-2">
                <li class="breadcrumb-item"><a href="{{ route('finance.expenses.index') }}">Expenses</a></li>
                <li class="breadcrumb-item"><a href="{{ route('finance.expenses.show', $expense) }}">{{ $expense->expense_no }}</a></li>
                <li class="breadcrumb-item active" aria-current="page">Edit</li>
            </ol>
        </nav>
        <h1 class="h3 mb-1">Edit {{ $expense->expense_no }}</h1>
        <p class="text-muted mb-0">Draft expenses can be updated until posted.</p>
    </div>

    @include('finance.partials.workspace-nav', ['active' => 'expenses'])

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('finance.expenses.update', $expense) }}" enctype="multipart/form-data">
                @csrf
                @method('PUT')
                @include('finance.expenses.partials.form', ['expense' => $expense])
                <div class="d-flex flex-wrap gap-2 mt-4">
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                    <a href="{{ route('finance.expenses.show', $expense) }}" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
@endsection
