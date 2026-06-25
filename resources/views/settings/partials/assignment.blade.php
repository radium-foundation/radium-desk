<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3">
        <h2 class="h6 mb-0">Assignment</h2>
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('settings.assignment.update') }}">
            @csrf
            @method('PUT')
            <div class="row g-3">
                <div class="col-md-4">
                    <label for="assignment_timezone" class="form-label">Timezone</label>
                    <select name="timezone" id="assignment_timezone" class="form-select @error('timezone') is-invalid @enderror" required>
                        @foreach($timezones as $timezone)
                            <option value="{{ $timezone }}" @selected(old('timezone', $assignment['timezone']) === $timezone)>{{ $timezone }}</option>
                        @endforeach
                    </select>
                    @error('timezone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label for="day_shift_start" class="form-label">Office Hours Start</label>
                    <input type="time" name="day_shift_start" id="day_shift_start" class="form-control @error('day_shift_start') is-invalid @enderror"
                           value="{{ old('day_shift_start', $assignment['day_shift_start']) }}" required>
                    @error('day_shift_start')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label for="day_shift_end" class="form-label">Office Hours End</label>
                    <input type="time" name="day_shift_end" id="day_shift_end" class="form-control @error('day_shift_end') is-invalid @enderror"
                           value="{{ old('day_shift_end', $assignment['day_shift_end']) }}" required>
                    @error('day_shift_end')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label for="day_shift_admin_user_id" class="form-label">Day Shift Admin</label>
                    <select name="day_shift_admin_user_id" id="day_shift_admin_user_id" class="form-select @error('day_shift_admin_user_id') is-invalid @enderror" required>
                        <option value="">Select admin</option>
                        @foreach($adminUsers as $adminUser)
                            <option value="{{ $adminUser->id }}" @selected((int) old('day_shift_admin_user_id', $assignment['day_shift_admin_user_id']) === $adminUser->id)>
                                {{ $adminUser->firstName() }} {{ $adminUser->lastName() }} ({{ $adminUser->email }})
                            </option>
                        @endforeach
                    </select>
                    @error('day_shift_admin_user_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label for="night_shift_admin_user_id" class="form-label">Night Shift Admin</label>
                    <select name="night_shift_admin_user_id" id="night_shift_admin_user_id" class="form-select @error('night_shift_admin_user_id') is-invalid @enderror" required>
                        <option value="">Select admin</option>
                        @foreach($adminUsers as $adminUser)
                            <option value="{{ $adminUser->id }}" @selected((int) old('night_shift_admin_user_id', $assignment['night_shift_admin_user_id']) === $adminUser->id)>
                                {{ $adminUser->firstName() }} {{ $adminUser->lastName() }} ({{ $adminUser->email }})
                            </option>
                        @endforeach
                    </select>
                    @error('night_shift_admin_user_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label for="fallback_admin_1_user_id" class="form-label">Fallback Admin 1</label>
                    <select name="fallback_admin_1_user_id" id="fallback_admin_1_user_id" class="form-select @error('fallback_admin_1_user_id') is-invalid @enderror">
                        <option value="">None</option>
                        @foreach($adminUsers as $adminUser)
                            <option value="{{ $adminUser->id }}" @selected((int) old('fallback_admin_1_user_id', $assignment['fallback_admin_1_user_id']) === $adminUser->id)>
                                {{ $adminUser->firstName() }} {{ $adminUser->lastName() }} ({{ $adminUser->email }})
                            </option>
                        @endforeach
                    </select>
                    @error('fallback_admin_1_user_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label for="fallback_admin_2_user_id" class="form-label">Fallback Admin 2</label>
                    <select name="fallback_admin_2_user_id" id="fallback_admin_2_user_id" class="form-select @error('fallback_admin_2_user_id') is-invalid @enderror">
                        <option value="">None</option>
                        @foreach($adminUsers as $adminUser)
                            <option value="{{ $adminUser->id }}" @selected((int) old('fallback_admin_2_user_id', $assignment['fallback_admin_2_user_id']) === $adminUser->id)>
                                {{ $adminUser->firstName() }} {{ $adminUser->lastName() }} ({{ $adminUser->email }})
                            </option>
                        @endforeach
                    </select>
                    @error('fallback_admin_2_user_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
            <div class="mt-4">
                <button type="submit" class="btn btn-primary">Save Assignment Settings</button>
            </div>
        </form>
    </div>
</div>
