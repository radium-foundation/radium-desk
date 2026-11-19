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
        <p class="text-muted mb-0">Full-day leave is the default. Coverage or policy exceptions require Management review and do not guarantee approval.</p>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('leave-requests.store') }}">
                @csrf
                <input type="hidden" name="duration" value="full_day">

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
                    <div class="col-12">
                        <div class="form-check">
                            <input type="checkbox" name="manual_exception" value="1" id="manual_exception"
                                   class="form-check-input @error('manual_exception') is-invalid @enderror"
                                   @checked(old('manual_exception'))>
                            <label class="form-check-label" for="manual_exception">
                                This is a manual policy exception (coverage conflict, weekend sandwich, or monthly limit).
                            </label>
                            @error('manual_exception')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                            <div class="form-text">Submitting an exception does not approve leave. Management will review role coverage before deciding.</div>
                        </div>
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