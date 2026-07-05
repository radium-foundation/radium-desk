@php
    use App\Enums\TeamAvailabilityStatus;

    $currentStatus = old('availability_status', $availability['status'] ?? TeamAvailabilityStatus::Offline->value);
    $showLeaveDates = $currentStatus === TeamAvailabilityStatus::OnLeave->value;
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
            Let operations know when you can receive work. On leave dates are used for assignment planning.
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
                    data-team-availability-status
                >
                    @foreach(TeamAvailabilityStatus::cases() as $status)
                        <option value="{{ $status->value }}" @selected($currentStatus === $status->value)>
                            {{ $status->label() }}
                        </option>
                    @endforeach
                </select>
                @error('availability_status')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div
                class="row g-3 mb-3 @unless($showLeaveDates) d-none @endunless"
                data-team-availability-leave-fields
            >
                <div class="col-md-6">
                    <label for="leave_start_date" class="form-label">Leave start date</label>
                    <input
                        type="date"
                        id="leave_start_date"
                        name="leave_start_date"
                        class="form-control @error('leave_start_date') is-invalid @enderror"
                        value="{{ old('leave_start_date', $availability['leave_start_date'] ?? '') }}"
                    >
                    @error('leave_start_date')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="col-md-6">
                    <label for="leave_end_date" class="form-label">Leave end date</label>
                    <input
                        type="date"
                        id="leave_end_date"
                        name="leave_end_date"
                        class="form-control @error('leave_end_date') is-invalid @enderror"
                        value="{{ old('leave_end_date', $availability['leave_end_date'] ?? '') }}"
                    >
                    @error('leave_end_date')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            @if(filled($availability['updated_at'] ?? null))
                <p class="text-muted small">
                    Last updated {{ display_app_timeline_relative(\Illuminate\Support\Carbon::parse($availability['updated_at'])) }}.
                </p>
            @endif

            <button type="submit" class="btn btn-primary">Update availability</button>
        </form>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const statusSelect = document.querySelector('[data-team-availability-status]');
        const leaveFields = document.querySelector('[data-team-availability-leave-fields]');

        if (!statusSelect || !leaveFields) {
            return;
        }

        statusSelect.addEventListener('change', () => {
            const onLeave = statusSelect.value === @json(TeamAvailabilityStatus::OnLeave->value);
            leaveFields.classList.toggle('d-none', !onLeave);
        });
    });
</script>
