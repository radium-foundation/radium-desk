<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3">
        <h2 class="h6 mb-0">General</h2>
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('settings.general.update') }}">
            @csrf
            @method('PUT')
            <div class="row g-3">
                <div class="col-md-6">
                    <label for="company_name" class="form-label">Company Name</label>
                    <input type="text" name="company_name" id="company_name" class="form-control @error('company_name') is-invalid @enderror"
                           value="{{ old('company_name', $general['company_name']) }}" required>
                    @error('company_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label for="company_email" class="form-label">Company Email</label>
                    <input type="email" name="company_email" id="company_email" class="form-control @error('company_email') is-invalid @enderror"
                           value="{{ old('company_email', $general['company_email']) }}" required>
                    @error('company_email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label for="general_timezone" class="form-label">Timezone</label>
                    <select name="timezone" id="general_timezone" class="form-select @error('timezone') is-invalid @enderror" required>
                        @foreach($timezones as $timezone)
                            <option value="{{ $timezone }}" @selected(old('timezone', $general['timezone']) === $timezone)>{{ $timezone }}</option>
                        @endforeach
                    </select>
                    @error('timezone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label">Logo</label>
                    <input type="text" class="form-control" value="Coming soon" disabled>
                    <div class="form-text">Logo upload will be available in a future release.</div>
                </div>
            </div>
            <div class="mt-4">
                <button type="submit" class="btn btn-primary">Save General Settings</button>
            </div>
        </form>
    </div>
</div>
