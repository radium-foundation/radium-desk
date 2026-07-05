@extends('layouts.app')

@section('title', 'Request Leave')

@section('content')
    <div class="mb-4">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-2">
                <li class="breadcrumb-item"><a href="{{ route('leave-requests.index') }}">Leave Requests</a></li>
                <li class="breadcrumb-item active" aria-current="page">Request Leave</li>
            </ol>
        </nav>
        <h1 class="h3 mb-1">Request Leave</h1>
        <p class="text-muted mb-0">Submit leave for operations approval. Pending leave does not block assignment.</p>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('leave-requests.store') }}">
                @csrf

                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="start_date" class="form-label">Start date</label>
                        <input type="date" id="start_date" name="start_date"
                               class="form-control @error('start_date') is-invalid @enderror"
                               value="{{ old('start_date') }}" required>
                        @error('start_date')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-md-6">
                        <label for="end_date" class="form-label">End date</label>
                        <input type="date" id="end_date" name="end_date"
                               class="form-control @error('end_date') is-invalid @enderror"
                               value="{{ old('end_date') }}" required>
                        @error('end_date')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-12">
                        <label for="reason" class="form-label">Reason</label>
                        <textarea id="reason" name="reason" rows="4"
                                  class="form-control @error('reason') is-invalid @enderror"
                                  required>{{ old('reason') }}</textarea>
                        @error('reason')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2 mt-4">
                    <button type="submit" class="btn btn-primary">Submit Request</button>
                    <a href="{{ route('leave-requests.index') }}" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
@endsection
