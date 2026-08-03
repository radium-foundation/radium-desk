@extends('layouts.app')

@section('title', 'Delete Warning')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Operations</p>
        <h1 class="h3 mb-1">Delete Locked Entry</h1>
    </div>

    <div class="alert alert-danger">
        <p class="mb-0 fw-semibold">This action will reverse the accounting journal.</p>
        <p class="mb-0 mt-2">Continue?</p>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <p class="mb-1"><strong>{{ $entry->entry_no }}</strong> · {{ $entry->type->label() }} · ₹{{ number_format((float) $entry->amount, 2) }}</p>
            <p class="text-muted mb-0 small">{{ $entry->categoryLabel() }} · {{ $entry->remark }}</p>
        </div>
    </div>

    <form method="POST" action="{{ route('cash-book.destroy', $entry) }}">
        @csrf
        @method('DELETE')
        <input type="hidden" name="confirmed" value="1">
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('cash-book.index') }}" class="btn btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-danger">Continue</button>
        </div>
    </form>
@endsection
