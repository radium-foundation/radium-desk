@props(['user', 'roles', 'currentRole' => null, 'showPassword' => false, 'showStatus' => false])

<div class="row g-3">
    <div class="col-md-6">
        <label for="first_name" class="form-label">First Name</label>
        <input type="text" name="first_name" id="first_name"
               class="form-control @error('first_name') is-invalid @enderror"
               value="{{ old('first_name', $user->first_name) }}" required>
        @error('first_name')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
    <div class="col-md-6">
        <label for="last_name" class="form-label">Last Name</label>
        <input type="text" name="last_name" id="last_name"
               class="form-control @error('last_name') is-invalid @enderror"
               value="{{ old('last_name', $user->last_name) }}">
        @error('last_name')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
    <div class="col-md-6">
        <label for="email" class="form-label">Email</label>
        <input type="email" name="email" id="email"
               class="form-control @error('email') is-invalid @enderror"
               value="{{ old('email', $user->email) }}" required>
        @error('email')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
    <div class="col-md-6">
        <label for="role" class="form-label">Role</label>
        <select name="role" id="role" class="form-select @error('role') is-invalid @enderror" required>
            <option value="">Select role</option>
            @foreach($roles as $role)
                <option value="{{ $role }}" @selected(old('role', $currentRole) === $role)>
                    {{ ucfirst($role) }}
                </option>
            @endforeach
        </select>
        @error('role')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
    <div class="col-md-6">
        <label for="bonvoice_extension" class="form-label">Mobile</label>
        <input type="text" name="bonvoice_extension" id="bonvoice_extension"
               class="form-control @error('bonvoice_extension') is-invalid @enderror"
               value="{{ old('bonvoice_extension', $user->bonvoice_extension) }}"
               placeholder="08448423017">
        @error('bonvoice_extension')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    @if($showPassword)
        <div class="col-md-6">
            <label for="password" class="form-label">Password</label>
            <input type="password" name="password" id="password"
                   class="form-control @error('password') is-invalid @enderror" required>
            @error('password')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
        <div class="col-md-6">
            <label for="password_confirmation" class="form-label">Confirm Password</label>
            <input type="password" name="password_confirmation" id="password_confirmation"
                   class="form-control" required>
        </div>
        <div class="col-12">
            @php
                $isActiveValue = old('is_active', '1');
            @endphp
            <div class="form-check form-switch">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" id="is_active" value="1"
                       class="form-check-input @error('is_active') is-invalid @enderror"
                       @checked(filter_var($isActiveValue, FILTER_VALIDATE_BOOLEAN))>
                <label class="form-check-label" for="is_active">Active</label>
                @error('is_active')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
        </div>
    @endif

    @if($showStatus)
        <div class="col-md-6">
            @php
                $isActiveValue = old('is_active', $user->is_active ? '1' : '0');
            @endphp
            <label for="is_active" class="form-label">Status</label>
            <div class="form-check form-switch">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" id="is_active" value="1"
                       class="form-check-input @error('is_active') is-invalid @enderror"
                       @checked(filter_var($isActiveValue, FILTER_VALIDATE_BOOLEAN))>
                <label class="form-check-label" for="is_active">Active</label>
                @error('is_active')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
        </div>
    @endif
</div>
