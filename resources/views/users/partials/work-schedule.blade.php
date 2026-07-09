@props([
    'user',
    'schedule' => [],
])

@php
    $weekdayLabels = config('workforce_calendar.weekday_labels', []);
    $selectedDays = old('weekly_off_days', $schedule['weekly_off_days'] ?? []);
@endphp

<div class="card border-0 shadow-sm mt-4">
    <div class="card-header bg-white py-3">
        <h2 class="h6 mb-0">Work Schedule</h2>
    </div>
    <div class="card-body">
        <p class="text-muted small mb-3">
            Official working calendar used before availability status for smart assignment.
        </p>

        @if(($schedule['configured'] ?? true) === false)
            <div class="alert alert-warning py-2 small mb-3" role="alert">
                Work schedule is not saved yet. Defaults shown below are for editing only.
                Morning Telegram briefings and calendar-based delivery will not run until you save.
            </div>
        @endif

        <form method="POST" action="{{ route('users.work-schedule.update', $user) }}">
            @csrf
            @method('PUT')

            <div class="row g-3">
                <div class="col-md-3">
                    <label for="work_start_time" class="form-label">Work start</label>
                    <input type="time" id="work_start_time" name="work_start_time"
                           class="form-control @error('work_start_time') is-invalid @enderror"
                           value="{{ old('work_start_time', $schedule['work_start_time'] ?? '09:00') }}" required>
                    @error('work_start_time')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="col-md-3">
                    <label for="work_end_time" class="form-label">Work end</label>
                    <input type="time" id="work_end_time" name="work_end_time"
                           class="form-control @error('work_end_time') is-invalid @enderror"
                           value="{{ old('work_end_time', $schedule['work_end_time'] ?? '18:00') }}" required>
                    @error('work_end_time')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="col-md-3">
                    <label for="lunch_start_time" class="form-label">Lunch start</label>
                    <input type="time" id="lunch_start_time" name="lunch_start_time"
                           class="form-control @error('lunch_start_time') is-invalid @enderror"
                           value="{{ old('lunch_start_time', $schedule['lunch_start_time'] ?? '13:30') }}">
                    @error('lunch_start_time')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="col-md-3">
                    <label for="lunch_end_time" class="form-label">Lunch end</label>
                    <input type="time" id="lunch_end_time" name="lunch_end_time"
                           class="form-control @error('lunch_end_time') is-invalid @enderror"
                           value="{{ old('lunch_end_time', $schedule['lunch_end_time'] ?? '14:00') }}">
                    @error('lunch_end_time')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="col-md-3">
                    <label for="short_break_count" class="form-label">Short breaks</label>
                    <input type="number" id="short_break_count" name="short_break_count" min="0" max="10"
                           class="form-control @error('short_break_count') is-invalid @enderror"
                           value="{{ old('short_break_count', $schedule['short_break_count'] ?? 2) }}" required>
                    @error('short_break_count')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="col-md-3">
                    <label for="short_break_minutes" class="form-label">Minutes each</label>
                    <input type="number" id="short_break_minutes" name="short_break_minutes" min="1" max="120"
                           class="form-control @error('short_break_minutes') is-invalid @enderror"
                           value="{{ old('short_break_minutes', $schedule['short_break_minutes'] ?? 10) }}" required>
                    @error('short_break_minutes')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="col-12">
                    <span class="form-label d-block">Weekly off days</span>
                    <div class="d-flex flex-wrap gap-3">
                        @foreach($weekdayLabels as $dayValue => $dayLabel)
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox"
                                       name="weekly_off_days[]"
                                       id="weekly_off_day_{{ $dayValue }}"
                                       value="{{ $dayValue }}"
                                       @checked(in_array($dayValue, $selectedDays, true))>
                                <label class="form-check-label" for="weekly_off_day_{{ $dayValue }}">
                                    {{ $dayLabel }}
                                </label>
                            </div>
                        @endforeach
                    </div>
                    @error('weekly_off_days')
                        <div class="text-danger small mt-1">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            @if(($schedule['expected_working_minutes'] ?? null) !== null)
                <p class="text-muted small mt-3 mb-0">
                    Expected working minutes: {{ number_format($schedule['expected_working_minutes']) }}
                </p>
            @endif

            <button type="submit" class="btn btn-primary mt-3">Save Work Schedule</button>
        </form>
    </div>
</div>
