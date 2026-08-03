@extends('layouts.app')

@section('title', 'Edit Warning')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Operations</p>
        <h1 class="h3 mb-1">Edit Locked Entry</h1>
    </div>

    <div class="alert alert-warning">
        <p class="mb-2 fw-semibold">This entry has already been posted to the ledger.</p>
        <p class="mb-0">Editing will reverse the previous journal and create a new one.</p>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <p class="mb-1"><strong>{{ $entry->entry_no }}</strong> · {{ $entry->type->label() }} · ₹{{ number_format((float) $entry->amount, 2) }}</p>
            <p class="text-muted mb-0 small">{{ $entry->categoryLabel() }} · {{ $entry->remark }}</p>
        </div>
    </div>

    <form method="POST" action="{{ route('cash-book.edit-acknowledge', $entry) }}">
        @csrf
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('cash-book.index') }}" class="btn btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-warning">Continue</button>
        </div>
    </form>
@endsection
