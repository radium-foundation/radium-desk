@extends('layouts.app')

@section('title', 'Edit Cash Book Entry')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Operations</p>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-2">
                <li class="breadcrumb-item"><a href="{{ route('cash-book.index') }}">Cash Book</a></li>
                <li class="breadcrumb-item active" aria-current="page">Edit {{ $entry->entry_no }}</li>
            </ol>
        </nav>
        <h1 class="h3 mb-1">Edit Entry</h1>
        <p class="text-muted mb-0">Saving will reverse the previous journal and post a corrected one.</p>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('cash-book.update', $entry) }}" id="cash-book-entry-form">
                @csrf
                @method('PUT')
                @include('cash-book.partials.form')
                <div class="d-flex flex-wrap gap-2 mt-4">
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                    <a href="{{ route('cash-book.index') }}" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
@endsection
