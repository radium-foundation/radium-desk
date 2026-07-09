@php
    use App\Enums\TeamAvailabilityStatus;

    $currentStatus = old('availability_status', $availability['status'] ?? TeamAvailabilityStatus::Offline->value);
    $restrictOffline = (bool) ($availability['restricts_offline_self_service'] ?? false);
    $statusOptions = TeamAvailabilityStatus::selfServiceCases($restrictOffline);
@endphp

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white">
        <h2 class="h6 mb-0">Team Availability</h2>
    </div>
    <div class="card-body">
        @if(session('status') === 'availability-updated')
            <div class="alert alert-success py-2 small" role="alert">
                Availability updated.
            </div>
        @endif

        <p class="text-muted small">
            Let operations know when you can receive work. Use leave requests for planned time off.
            @if($restrictOffline)
                While you are on duty, log out to end your shift or set Busy if temporarily unavailable.
            @endif
        </p>

        <form method="POST" action="{{ route('profile.availability.update') }}">
            @csrf
            @method('patch')

            <div class="mb-3">
                <label for="availability_status" class="form-label">Status</label>
                <select
                    id="availability_status"
                    name="availability_status"
                    class="form-select @error('availability_status') is-invalid @enderror"
                >
                    @foreach($statusOptions as $status)
                        <option value="{{ $status->value }}" @selected($currentStatus === $status->value)>
                            {{ $status->label() }}
                        </option>
                    @endforeach
                </select>
                @error('availability_status')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            @if(filled($availability['updated_at'] ?? null))
                <p class="text-muted small">
                    Last updated {{ display_app_timeline_relative(\Illuminate\Support\Carbon::parse($availability['updated_at'])) }}.
                </p>
            @endif

            <div class="d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-primary">Update availability</button>
                @can('create', \App\Models\LeaveRequest::class)
                    <a href="{{ route('leave-requests.create') }}" class="btn btn-outline-primary">Request Leave</a>
                @endcan
            </div>
        </form>
    </div>
</div>
