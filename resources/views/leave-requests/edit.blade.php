@extends('layouts.app')

@section('title', 'Edit Leave Request')

@section('content')
    <div class="mb-4">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-2">
                <li class="breadcrumb-item"><a href="{{ route('leave-requests.index') }}">Leave Requests</a></li>
                <li class="breadcrumb-item"><a href="{{ route('leave-requests.show', $leaveRequest) }}">Details</a></li>
                <li class="breadcrumb-item active" aria-current="page">Edit</li>
            </ol>
        </nav>
        <h1 class="h3 mb-1">Edit Pending Leave</h1>
        <p class="text-muted mb-0">Full-day leave only. Use the manual exception flag when policy requires Management review.</p>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('leave-requests.update', $leaveRequest) }}">
                @csrf
                @method('PUT')
                <input type="hidden" name="duration" value="full_day">

                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="start_date" class="form-label">Start date</label>
                        <input type="date" id="start_date" name="start_date"
                               class="form-control @error('start_date') is-invalid @enderror"
                               value="{{ old('start_date', $leaveRequest->start_date?->toDateString()) }}" required>
                        @error('start_date')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-md-6">
                        <label for="end_date" class="form-label">End date</label>
                        <input type="date" id="end_date" name="end_date"
                               class="form-control @error('end_date') is-invalid @enderror"
                               value="{{ old('end_date', $leaveRequest->end_date?->toDateString()) }}" required>
                        @error('end_date')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-12">
                        <label for="reason" class="form-label">Reason</label>
                        <textarea id="reason" name="reason" rows="4"
                                  class="form-control @error('reason') is-invalid @enderror"
                                  required>{{ old('reason', $leaveRequest->reason) }}</textarea>
                        @error('reason')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-12">
                        <div class="form-check">
                            <input type="checkbox" name="manual_exception" value="1" id="manual_exception"
                                   class="form-check-input @error('manual_exception') is-invalid @enderror"
                                   @checked(old('manual_exception', $leaveRequest->is_manual_exception))>
                            <label class="form-check-label" for="manual_exception">Manual policy exception</label>
                            @error('manual_exception')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2 mt-4">
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                    <a href="{{ route('leave-requests.show', $leaveRequest) }}" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
@endsection