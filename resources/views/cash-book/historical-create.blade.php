@extends('layouts.app')

@section('title', 'Import Historical Entry')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Operations</p>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-2">
                <li class="breadcrumb-item"><a href="{{ route('cash-book.index') }}">Cash Book</a></li>
                <li class="breadcrumb-item active" aria-current="page">Historical Import</li>
            </ol>
        </nav>
        <h1 class="h3 mb-1">Import Historical Entry</h1>
        <p class="text-muted mb-0">Super Admin only. Manual entry — no CSV. Posts to the ledger using the original date.</p>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('cash-book.historical.store') }}" id="cash-book-entry-form">
                @csrf
                @include('cash-book.partials.form', ['historical' => true])
                <div class="mt-3">
                    <label for="historical_reason" class="form-label">Reason <span class="text-danger">*</span></label>
                    <input
                        type="text"
                        name="historical_reason"
                        id="historical_reason"
                        class="form-control @error('historical_reason') is-invalid @enderror"
                        value="{{ old('historical_reason') }}"
                        required
                        maxlength="500"
                        placeholder="Historical Import — e.g. Opening cash from April ledger"
                    >
                    @error('historical_reason')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="d-flex flex-wrap gap-2 mt-4">
                    <button type="submit" class="btn btn-primary">Review Entry</button>
                    <a href="{{ route('cash-book.index') }}" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
@endsection
